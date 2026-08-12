<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Service;

use InvalidArgumentException;

/**
 * Thrown when a ticket-number pattern cannot be parsed. The message is already
 * translated and safe to show on the configuration page.
 */
final class InvalidPatternException extends InvalidArgumentException
{
}
