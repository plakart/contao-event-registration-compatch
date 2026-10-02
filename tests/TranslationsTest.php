<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TranslationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'de' => ['de'];
        yield 'en' => ['en'];
    }

    #[DataProvider('localeProvider')]
    public function testAllKeysExist(string $locale): void
    {
        $messages = Yaml::parseFile(__DIR__.'/../translations/plakart_event_registration_compatch.'.$locale.'.yaml');

        $this->assertSame(
            ['cancel_button', 'cancel_question', 'confirm_button', 'confirm_question'],
            $this->sortedKeys($messages),
        );
        $this->assertStringContainsString('%events%', $messages['confirm_question']);
        $this->assertStringContainsString('%events%', $messages['cancel_question']);
    }

    /**
     * @param array<string, string> $messages
     *
     * @return list<string>
     */
    private function sortedKeys(array $messages): array
    {
        $keys = array_keys($messages);
        sort($keys);

        return $keys;
    }
}
