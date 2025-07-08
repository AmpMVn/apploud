<?php

declare(strict_types=1);

namespace App\Dto;

use InvalidArgumentException;

final readonly class GroupDto
{
    public function __construct(
        public int $id,
        public string $fullPath,
    ) {}

    /**
     * @param array{id: mixed, full_path: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_numeric($data['id']) ? (int) $data['id'] : throw new InvalidArgumentException('ID must be numeric'),
            fullPath: \is_string($data['full_path']) ? $data['full_path'] : throw new InvalidArgumentException('Full path must be string'),
        );
    }
}
