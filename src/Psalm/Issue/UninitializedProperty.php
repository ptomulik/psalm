<?php

declare(strict_types=1);

namespace Psalm\Issue;

final class UninitializedProperty extends PropertyIssue
{
    public const ERROR_LEVEL = 7;
    public const SHORTCODE = 186;
}
