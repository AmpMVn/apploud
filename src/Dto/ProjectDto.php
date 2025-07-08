<?php

declare(strict_types=1);

namespace App\Dto;

use InvalidArgumentException;

final readonly class ProjectDto
{
    public function __construct(
        public int $id,
        public string $pathWithNamespace,
    ) {}

    /**
     * @param array{id: mixed, path_with_namespace: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_numeric($data['id']) ? (int) $data['id'] : throw new InvalidArgumentException('ID must be numeric'),
            pathWithNamespace: \is_string($data['path_with_namespace']) ? $data['path_with_namespace'] : throw new InvalidArgumentException('Path with namespace must be string'),
        );
    }
}
