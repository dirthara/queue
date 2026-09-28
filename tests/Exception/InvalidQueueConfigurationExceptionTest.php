<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\Exception\QueueException;
use Dirthara\Queue\Exception\InvalidQueueConfigurationException;

final class InvalidQueueConfigurationExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_nothing_by_default(): void
    {
        $exception = new InvalidQueueConfigurationException();

        self::assertInstanceOf(QueueException::class, $exception);
        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertSame('', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
        self::assertSame([], $exception->context);
    }

    #[Test]
    public function it_keeps_a_previous_exception_and_its_context(): void
    {
        $previous = new InvalidArgumentException('cause');
        $exception = new InvalidQueueConfigurationException('message', 3, $previous, ['driver' => 'Missing']);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(3, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['driver' => 'Missing'], $exception->context);
    }

    #[Test]
    public function it_merges_what_is_added_to_its_context(): void
    {
        $exception = new InvalidQueueConfigurationException(context: ['driver' => 'Missing', 'kept' => true]);

        self::assertSame($exception, $exception->addContext(['driver' => 'Replaced', 'queue' => 'default']));
        self::assertSame(['driver' => 'Replaced', 'kept' => true, 'queue' => 'default'], $exception->context);
    }

    #[Test]
    public function it_describes_a_missing_option(): void
    {
        $exception = InvalidQueueConfigurationException::missingOption(
            "class@anonymous\0/app/src/Job.php:3$0",
            "pass\nword",
        );

        self::assertSame(
            'Unable to configure the "class@anonymous\\000/app/src/Job.php:3$0" queue: the option "pass\\nword" is required and has no default.',
            $exception->getMessage(),
        );
        self::assertSame(
            ['driver' => 'class@anonymous\\000/app/src/Job.php:3$0', 'option' => 'pass\\nword'],
            $exception->context,
        );
    }

    #[Test]
    public function it_describes_an_option_of_the_wrong_type(): void
    {
        $exception = InvalidQueueConfigurationException::invalidOptionType(
            "class@anonymous\0/app/src/Job.php:3$0",
            "pass\nword",
            'string',
            42,
        );

        self::assertSame(
            'Unable to configure the "class@anonymous\\000/app/src/Job.php:3$0" queue: the option "pass\\nword" has to be of type string, int given.',
            $exception->getMessage(),
        );
        self::assertSame(
            [
                'driver' => 'class@anonymous\\000/app/src/Job.php:3$0',
                'option' => 'pass\\nword',
                'expected' => 'string',
                'actual' => 'int',
            ],
            $exception->context,
        );
    }
}
