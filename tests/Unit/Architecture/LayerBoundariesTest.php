<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The layering claim in the README, checked by the build instead of by trust.
 *
 * Architecture rots one convenient import at a time: someone needs the database
 * inside an entity "just this once" and nothing complains. This test complains.
 */
final class LayerBoundariesTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../../src';

    /**
     * The domain is the point of the whole arrangement: it may know about PHP
     * itself and about nothing else. No framework, no database driver, no HTTP,
     * not even the use cases that orchestrate it.
     */
    public function testTheDomainDependsOnNothingButPhp(): void
    {
        $violations = [];

        foreach ($this->importsIn('Domain') as $file => $imports) {
            foreach ($imports as $import) {
                // An import with no namespace separator is a built-in class such
                // as DateTimeImmutable; anything namespaced must be our domain.
                if (str_contains($import, '\\') && !str_starts_with($import, 'OrderApi\\Domain\\')) {
                    $violations[] = sprintf('%s imports %s', $file, $import);
                }
            }
        }

        self::assertSame([], $violations, "The domain must not depend on anything outside itself:\n" . implode("\n", $violations));
    }

    /**
     * Use cases talk to the outside world through their ports. If one of them
     * names a concrete adapter, the port has stopped being a seam.
     */
    #[DataProvider('forbiddenInApplication')]
    public function testTheApplicationDoesNotReachForConcreteAdapters(string $forbiddenPrefix): void
    {
        $violations = [];

        foreach ($this->importsIn('Application') as $file => $imports) {
            foreach ($imports as $import) {
                if (str_starts_with($import, $forbiddenPrefix)) {
                    $violations[] = sprintf('%s imports %s', $file, $import);
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenInApplication(): iterable
    {
        yield 'infrastructure' => ['OrderApi\Infrastructure\\'];
        yield 'http' => ['OrderApi\Http\\'];
    }

    /**
     * Controllers may hold use cases and PSR-7; reaching past them into a
     * repository or a carrier client would put orchestration in the wrong place.
     */
    public function testHttpDoesNotReachPastTheApplicationLayer(): void
    {
        $violations = [];

        foreach ($this->importsIn('Http') as $file => $imports) {
            foreach ($imports as $import) {
                if (str_starts_with($import, 'OrderApi\Infrastructure\\')) {
                    $violations[] = sprintf('%s imports %s', $file, $import);
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function testTheRulesAreActuallyLookingAtFiles(): void
    {
        self::assertNotEmpty($this->importsIn('Domain'), 'No domain sources were scanned.');
    }

    /**
     * @return array<string, list<string>>
     */
    private function importsIn(string $layer): array
    {
        $imports = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::SOURCE . '/' . $layer, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/^use\s+([^;\s]+)\s*;/m', $source, $matches);

            $imports[$layer . '/' . $file->getFilename()] = $matches[1];
        }

        return $imports;
    }
}
