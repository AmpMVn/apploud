<?php

declare(strict_types=1);

namespace App\Dto;

use InvalidArgumentException;

final readonly class MemberDto
{
    public function __construct(
        public string $username,
        public string $name,
        public int $accessLevel,
    ) {}

    /**
     * @param array{username: mixed, name: mixed, access_level: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            username: \is_string($data['username']) ? $data['username'] : throw new InvalidArgumentException('Username must be string'),
            name: \is_string($data['name']) ? $data['name'] : throw new InvalidArgumentException('Name must be string'),
            accessLevel: is_numeric($data['access_level']) ? (int) $data['access_level'] : throw new InvalidArgumentException('Access level must be numeric'),
        );
    }
}
