<?php

declare(strict_types=1);

namespace Psalm\Issue;

final class PossiblyNullPropertyAssignmentValue extends PropertyIssue
{
    public const ERROR_LEVEL = 3;
    public const SHORTCODE = 148;
}
