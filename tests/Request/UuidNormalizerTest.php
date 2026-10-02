<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Request;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Plakart\ContaoEventRegistrationCompatch\Request\UuidNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UuidNormalizerTest extends TestCase
{
    public function testScalarUuid(): void
    {
        $this->assertSame(['abc'], UuidNormalizer::normalize('abc'));
    }

    public function testListOfUuids(): void
    {
        $this->assertSame(['abc', 'def'], UuidNormalizer::normalize(['abc', 'def']));
    }

    public function testDuplicatesAreRemoved(): void
    {
        $this->assertSame(['abc', 'def'], UuidNormalizer::normalize(['abc', 'def', 'abc']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'empty array' => [[]];
        yield 'nested array' => [['abc', ['def']]];
        yield 'empty element' => [['abc', '']];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidInputIsNotFound(mixed $raw): void
    {
        $this->expectException(PageNotFoundException::class);

        UuidNormalizer::normalize($raw);
    }
}
