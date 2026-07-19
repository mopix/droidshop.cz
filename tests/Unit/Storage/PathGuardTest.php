<?php

namespace Tests\Unit\Storage;

use App\Core\Storage\Exceptions\UnsafePath;
use App\Core\Storage\PathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gate between a module's path and the filesystem. Every case here is a
 * way one tenant could otherwise reach another tenant's files, or the host's.
 */
class PathGuardTest extends TestCase
{
    private PathGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new PathGuard;
    }

    /**
     * @return list<array{string}>
     */
    public static function safePaths(): array
    {
        return [
            ['products/5/main.jpg'],
            ['a/b/c.png'],
            ['logo.svg'],
            ['deep/nested/path/file.webp'],
            ['file-with-dashes_and_underscores.pdf'],
        ];
    }

    #[DataProvider('safePaths')]
    public function test_safe_paths_pass_through(string $path): void
    {
        $this->assertSame($path, $this->guard->clean($path));
    }

    /**
     * @return list<array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            'parent traversal' => ['../secret.jpg'],
            'nested traversal' => ['a/../../b.jpg'],
            'absolute path' => ['/etc/passwd'],
            'backslash traversal' => ['..\\windows'],
            'null byte' => ["file\0.jpg"],
            'empty' => [''],
            'only slashes' => ['///'],
            'only dots' => ['..'],
            'traversal at end' => ['a/b/..'],
            'encoded-looking traversal' => ['a/%2e%2e/b'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_paths_are_rejected(string $path): void
    {
        $this->expectException(UnsafePath::class);

        $this->guard->clean($path);
    }

    public function test_absolute_path_is_rejected(): void
    {
        // An absolute path is a caller error. Rejecting is safer than
        // reinterpreting it as a relative key, which would be surprising.
        $this->expectException(UnsafePath::class);

        $this->guard->clean('/products/x.jpg');
    }

    public function test_prefixing_forces_the_tenant_root(): void
    {
        $this->assertSame('tenants/12/products/x.jpg', $this->guard->prefixed(12, 'products/x.jpg'));
    }

    public function test_prefixing_a_traversal_still_throws(): void
    {
        $this->expectException(UnsafePath::class);

        $this->guard->prefixed(12, '../11/products/x.jpg');
    }
}
