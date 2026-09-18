<?php

declare(strict_types=1);

namespace Hn\McpServer\Exception;

/**
 * A payload exceeded the configured `maxFileSizeMb`; HTTP callers map it to 413.
 */
final class UploadTooLargeException extends ValidationException {}
