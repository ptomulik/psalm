<?php

declare(strict_types=1);

namespace Psalm;

use Exception;
use LogicException;
use PhpParser;
use Psalm\Internal\Analyzer\CommentAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Storage\ImmutableNonCloneableTrait;
use UnexpectedValueException;

use function explode;
use function max;
use function mb_strcut;
use function min;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function str_replace;
use function strlen;
use function strpos;
use function strrpos;
use function substr_count;
use function trim;

use const PREG_OFFSET_CAPTURE;

/**
 * @psalm-immutable
 */
class CodeLocation
{
    use ImmutableNonCloneableTrait;

    public string $file_path;

    public string $file_name;

    public int $raw_line_number;

    private int $end_line_number = -1;

    public int $raw_file_start;

    public int $raw_file_end;

    protected int $file_start;

    protected int $file_end;

    protected bool $single_line;

    protected int $preview_start;

    private int $preview_end = -1;

    private int $selection_start = -1;

    private int $selection_end = -1;

    private int $column_from = -1;

    private int $column_to = -1;

    private string $snippet = '';

    private ?string $text = null;

    public ?int $docblock_start = null;

    private ?int $docblock_start_line_number = null;

    protected ?int $docblock_line_number = null;

    private ?int $regex_type = null;

    private bool $have_recalculated = false;

    public ?CodeLocation $previous_location = null;

    public const VAR_TYPE = 0;
    public const FUNCTION_RETURN_TYPE = 1;
    public const FUNCTION_PARAM_TYPE = 2;
    public const FUNCTION_PHPDOC_RETURN_TYPE = 3;
    public const FUNCTION_PHPDOC_PARAM_TYPE = 4;
    public const FUNCTION_PARAM_VAR = 5;
    public const CATCH_VAR = 6;
    public const FUNCTION_PHPDOC_METHOD = 7;

    private const PROPERTY_KEYS_FOR_UNSERIALIZE = [
        'file_path' => 'file_path',
        'file_name' => 'file_name',
        'raw_line_number' => 'raw_line_number',
        "\0" . self::class . "\0" . 'end_line_number' => 'end_line_number',
        'raw_file_start' => 'raw_file_start',
        'raw_file_end' => 'raw_file_end',
        "\0*\0" . 'file_start' => 'file_start',
        "\0*\0" . 'file_end' => 'file_end',
        "\0*\0" . 'single_line' => 'single_line',
        "\0*\0" . 'preview_start' => 'preview_start',
        "\0" . self::class . "\0" . 'preview_end' => 'preview_end',
        "\0" . self::class . "\0" . 'selection_start' => 'selection_start',
        "\0" . self::class . "\0" . 'selection_end' => 'selection_end',
        "\0" . self::class . "\0" . 'column_from' => 'column_from',
        "\0" . self::class . "\0" . 'column_to' => 'column_to',
        "\0" . self::class . "\0" . 'snippet' => 'snippet',
        "\0" . self::class . "\0" . 'text' => 'text',
        'docblock_start' => 'docblock_start',
        "\0" . self::class . "\0" . 'docblock_start_line_number' => 'docblock_start_line_number',
        "\0*\0" . 'docblock_line_number' => 'docblock_line_number',
        "\0" . self::class . "\0" . 'regex_type' => 'regex_type',
        "\0" . self::class . "\0" . 'have_recalculated' => 'have_recalculated',
        'previous_location' => 'previous_location',
    ];

    public function __construct(
        FileSource $file_source,
        PhpParser\Node $stmt,
        ?CodeLocation $previous_location = null,
        bool $single_line = false,
        ?int $regex_type = null,
        ?string $selected_text = null,
        ?int $comment_line = null,
    ) {
        /** @psalm-suppress ImpureMethodCall Actually mutation-free just not marked */
        $this->file_start = (int)$stmt->getAttribute('startFilePos');
        /** @psalm-suppress ImpureMethodCall Actually mutation-free just not marked */
        $this->file_end = (int)$stmt->getAttribute('endFilePos');
        $this->raw_file_start = $this->file_start;
        $this->raw_file_end = $this->file_end;
        $this->file_path = $file_source->getFilePath();
        $this->file_name = $file_source->getFileName();
        $this->single_line = $single_line;
        $this->regex_type = $regex_type;
        $this->previous_location = $previous_location;
        $this->text = $selected_text;

        /** @psalm-suppress ImpureMethodCall Actually mutation-free just not marked */
        $doc_comment = $stmt->getDocComment();

        $this->docblock_start = $doc_comment ? $doc_comment->getStartFilePos() : null;
        $this->docblock_start_line_number = $doc_comment ? $doc_comment->getStartLine() : null;

        $this->preview_start = $this->docblock_start ?: $this->file_start;

        /** @psalm-suppress ImpureMethodCall Actually mutation-free just not marked */
        $this->raw_line_number = $stmt->getStartLine();

        $this->docblock_line_number = $comment_line;
    }

    /**
     * Suppresses memory usage when unserializing objects.
     *
     * @see \Psalm\Storage\UnserializeMemoryUsageSuppressionTrait
     */
    public function __unserialize(array $properties): void
    {
        foreach (self::PROPERTY_KEYS_FOR_UNSERIALIZE as $key => $property_name) {
            /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
            $this->$property_name = $properties[$key];
        }
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod Part of public API
     * @return static
     */
    public function setCommentLine(?int $line): self
    {
        if ($line === $this->docblock_line_number) {
            return $this;
        }
        $cloned = clone $this;
        $cloned->docblock_line_number = $line;
        return $cloned;
    }

    /**
     * @psalm-external-mutation-free
     * @psalm-suppress InaccessibleProperty Mainly used for caching
     */
    private function calculateRealLocation(): void
    {
        if ($this->have_recalculated) {
            return;
        }

        $this->have_recalculated = true;

        $this->selection_start = $this->file_start;
        $this->selection_end = $this->file_end + 1;

        $project_analyzer = ProjectAnalyzer::getInstance();

        $codebase = $project_analyzer->getCodebase();

        /** @psalm-suppress ImpureMethodCall */
        $file_contents = $codebase->getFileContents($this->file_path);

        $file_length = strlen($file_contents);

        $search_limit = $this->single_line ? $this->selection_start : $this->selection_end;

        if ($search_limit <= $file_length) {
            $preview_end = strpos(
                $file_contents,
                "\n",
                $search_limit,
            );
        } else {
            $preview_end = false;
        }

        // if the string didn't contain a newline
        if ($preview_end === false) {
            $preview_end = $this->selection_end;
        }

        $this->preview_end = $preview_end;

        if ($this->docblock_line_number &&
            $this->docblock_start_line_number &&
            $this->preview_start < $this->selection_start
        ) {
            $preview_lines = explode(
                "\n",
                mb_strcut(
                    $file_contents,
                    $this->preview_start,
                    $this->selection_start - $this->preview_start - 1,
                ),
            );

            $preview_offset = 0;

            $comment_line_offset = $this->docblock_line_number - $this->docblock_start_line_number;

            for ($i = 0; $i < $comment_line_offset; ++$i) {
                $preview_offset += strlen($preview_lines[$i]) + 1;
            }

            if (!isset($preview_lines[$i])) {
                throw new Exception('Should have offset');
            }

            $key_line = $preview_lines[$i];

            $indentation = (int)strpos($key_line, '@');

            $key_line = trim((string) preg_replace('@\**/\s*@', '', mb_strcut($key_line, $indentation)));

            $this->selection_start = $preview_offset + $indentation + $this->preview_start;
            $this->selection_end = $this->selection_start + strlen($key_line);
        }

        if ($this->regex_type !== null) {
            $regex = match ($this->regex_type) {
                self::VAR_TYPE => '/@(?:psalm-)?var[ \t]+' . CommentAnalyzer::TYPE_REGEX . '/',
                self::FUNCTION_RETURN_TYPE => '/\\:\s+(\\??\s*[A-Za-z0-9_\\\\\[\]]+)/',
                self::FUNCTION_PARAM_TYPE => '/^(\\??\s*[A-Za-z0-9_\\\\\[\]]+)\s/',
                self::FUNCTION_PHPDOC_RETURN_TYPE => '/@(?:psalm-)?return[ \t]+' . CommentAnalyzer::TYPE_REGEX . '/',
                self::FUNCTION_PHPDOC_METHOD => '/@(?:psalm-)?method[ \t]+(.*)/',
                self::FUNCTION_PHPDOC_PARAM_TYPE => '/@(?:psalm-)?param[ \t]+' . CommentAnalyzer::TYPE_REGEX . '/',
                self::FUNCTION_PARAM_VAR => '/(\$[^ ]*)/',
                self::CATCH_VAR => '/(\$[^ ^\)]*)/',
                default => throw new UnexpectedValueException('Unrecognised regex type ' . $this->regex_type),
            };

            $preview_snippet = mb_strcut(
                $file_contents,
                $this->selection_start,
                $this->selection_end - $this->selection_start,
            );

            if ($this->text) {
                $regex = '/(' . str_replace(',', ',[ ]*', preg_quote($this->text, '/')) . ')/';
            }

            if (preg_match($regex, $preview_snippet, $matches, PREG_OFFSET_CAPTURE)) {
                if (!isset($matches[1]) || $matches[1][1] === -1) {
                    throw new LogicException(
                        "Failed to match anything to 1st capturing group, "
                        . "or regex doesn't contain 1st capturing group, regex type " . $this->regex_type,
                    );
                }
                $this->selection_start = $this->selection_start + $matches[1][1];
                $this->selection_end = $this->selection_start + strlen($matches[1][0]);
            }
        }

        // reset preview start to beginning of line
        $this->preview_start = (int)strrpos(
            $file_contents,
            "\n",
            min($this->preview_start, $this->selection_start) - strlen($file_contents),
        ) + 1;

        $this->selection_start = max($this->preview_start, $this->selection_start);
        $this->selection_end = min($this->preview_end, $this->selection_end);

        if ($this->preview_end - $this->selection_end > 200) {
            $this->preview_end = (int)strrpos(
                $file_contents,
                "\n",
                $this->selection_end + 200 - strlen($file_contents),
            );

            // if the line is over 200 characters long
            if ($this->preview_end < $this->selection_end) {
                $this->preview_end = $this->selection_end + 50;
            }
        }

        $this->snippet = mb_strcut($file_contents, $this->preview_start, $this->preview_end - $this->preview_start);
        // text is within snippet. It's 50% faster to cut it from the snippet than from the full text
        $selection_length = $this->selection_end - $this->selection_start;
        $this->text = mb_strcut($this->snippet, $this->selection_start - $this->preview_start, $selection_length);

        // reset preview start to beginning of line
        if ($file_contents !== '') {
            $this->column_from = $this->selection_start -
                (int)strrpos($file_contents, "\n", $this->selection_start - strlen($file_contents));
        } else {
            $this->column_from = $this->selection_start;
        }

        $newlines = substr_count($this->text, "\n");

        if ($newlines) {
            $last_newline_pos = strrpos($file_contents, "\n", $this->selection_end - strlen($file_contents) - 1);
            $this->column_to = $this->selection_end - (int)$last_newline_pos;
        } else {
            $this->column_to = $this->column_from + strlen($this->text);
        }

        $this->end_line_number = $this->getLineNumber() + $newlines;
    }

    public function getLineNumber(): int
    {
        return $this->docblock_line_number ?: $this->raw_line_number;
    }

    public function getEndLineNumber(): int
    {
        $this->calculateRealLocation();

        return $this->end_line_number;
    }

    public function getSnippet(): string
    {
        $this->calculateRealLocation();

        return $this->snippet;
    }

    public function getSelectedText(): string
    {
        $this->calculateRealLocation();

        return (string)$this->text;
    }

    public function getColumn(): int
    {
        $this->calculateRealLocation();

        return $this->column_from;
    }

    public function getEndColumn(): int
    {
        $this->calculateRealLocation();

        return $this->column_to;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function getSelectionBounds(): array
    {
        $this->calculateRealLocation();

        return [$this->selection_start, $this->selection_end];
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function getSnippetBounds(): array
    {
        $this->calculateRealLocation();

        return [$this->preview_start, $this->preview_end];
    }

    public function getHash(): string
    {
        return $this->file_name . ' ' . $this->raw_file_start . $this->raw_file_end;
    }

    public function getShortSummary(): string
    {
        return $this->file_name . ':' . $this->getLineNumber() . ':' . $this->getColumn();
    }
}
