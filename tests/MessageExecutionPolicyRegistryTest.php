<?php

declare(strict_types=1);

namespace Dirthara\Queue\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\Queue\Retry\NeverRetryPolicy;
use Dirthara\Queue\Backoff\NoBackoffPolicy;
use Dirthara\Queue\Retry\AttemptsRetryPolicy;
use Dirthara\Queue\Tests\Fixtures\Maintenance;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Queue\MessageExecutionPolicyRegistry;
use Dirthara\Queue\Tests\Fixtures\CustomerMessage;
use Dirthara\Queue\Tests\Fixtures\GenerateInvoice;
use Dirthara\Queue\Tests\Fixtures\SendWelcomeEmail;
use Dirthara\Queue\Backoff\ExponentialBackoffPolicy;
use Dirthara\Queue\ValueObject\MessageExecutionPolicy;
use Dirthara\Queue\Exception\InvalidMessageTypeException;
use Dirthara\Queue\Tests\Fixtures\Inheritance\TakePayment;
use Dirthara\Queue\Contract\MessageExecutionPolicyProvider;
use Dirthara\Queue\Tests\Fixtures\Inheritance\NotifyCustomer;
use Dirthara\Queue\Tests\Fixtures\Inheritance\PaymentMessage;
use Dirthara\Queue\Tests\Fixtures\Inheritance\NotifyVipCustomer;
use Dirthara\Queue\Exception\DuplicateMessageExecutionPolicyException;
use Dirthara\Queue\Contract\MessageExecutionPolicyRegistry as MessageExecutionPolicyRegistryContract;

use function strtolower;
use function strtoupper;

final class MessageExecutionPolicyRegistryTest extends TestCase
{
    #[Test]
    public function it_is_an_execution_policy_provider_and_registry(): void
    {
        $registry = new MessageExecutionPolicyRegistry(self::policy());

        self::assertInstanceOf(MessageExecutionPolicyProvider::class, $registry);
        self::assertInstanceOf(MessageExecutionPolicyRegistryContract::class, $registry);
    }

    #[Test]
    public function it_exposes_its_default_policy(): void
    {
        $default = self::policy();

        self::assertSame($default, new MessageExecutionPolicyRegistry($default)->default);
    }

    #[Test]
    public function it_gives_an_unregistered_message_the_default_policy(): void
    {
        $default = self::policy();

        self::assertSame(
            $default,
            new MessageExecutionPolicyRegistry($default)->policyFor(new SendWelcomeEmail('ada@example.com')),
        );
    }

    #[Test]
    public function it_gives_a_registered_message_type_its_own_policy(): void
    {
        $registry = new MessageExecutionPolicyRegistry(self::policy());
        $webhook = self::policy();

        $registry->register(SendWelcomeEmail::class, $webhook);

        self::assertSame($webhook, $registry->policyFor(new SendWelcomeEmail('ada@example.com')));
    }

    #[Test]
    public function it_keeps_an_override_to_its_own_message_type(): void
    {
        $default = self::policy();
        $registry = new MessageExecutionPolicyRegistry($default);
        $welcome = self::policy();
        $maintenance = self::policy();

        $registry->register(SendWelcomeEmail::class, $welcome);
        $registry->register(Maintenance::class, $maintenance);

        self::assertSame($welcome, $registry->policyFor(new SendWelcomeEmail('ada@example.com')));
        self::assertSame($maintenance, $registry->policyFor(Maintenance::PurgeExpiredSessions));
        self::assertSame($default, $registry->policyFor(new GenerateInvoice('INV-1')));
    }

    #[Test]
    public function it_does_not_give_a_subclass_the_policy_of_its_parent(): void
    {
        $default = self::policy();
        $registry = new MessageExecutionPolicyRegistry($default);
        $parent = self::policy();

        $registry->register(NotifyCustomer::class, $parent);

        self::assertSame($parent, $registry->policyFor(new NotifyCustomer('ada')));
        self::assertSame($default, $registry->policyFor(new NotifyVipCustomer('ada')));
    }

    #[Test]
    public function it_does_not_give_a_parent_the_policy_of_its_subclass(): void
    {
        $default = self::policy();
        $registry = new MessageExecutionPolicyRegistry($default);
        $child = self::policy();

        $registry->register(NotifyVipCustomer::class, $child);

        self::assertSame($child, $registry->policyFor(new NotifyVipCustomer('ada')));
        self::assertSame($default, $registry->policyFor(new NotifyCustomer('ada')));
    }

    #[Test]
    public function it_gives_the_concrete_class_of_an_abstract_parent_its_own_policy(): void
    {
        $registry = new MessageExecutionPolicyRegistry(self::policy());
        $payment = self::policy();

        $registry->register(TakePayment::class, $payment);

        self::assertSame($payment, $registry->policyFor(new TakePayment('PAY-1')));
    }

    #[Test]
    public function it_matches_a_type_registered_in_other_letter_case_or_with_a_leading_backslash(): void
    {
        $registry = new MessageExecutionPolicyRegistry(self::policy());
        $welcome = self::policy();
        $invoice = self::policy();

        $registry->register('\\' . SendWelcomeEmail::class, $welcome);
        $registry->register(strtolower(GenerateInvoice::class), $invoice);

        self::assertSame($welcome, $registry->policyFor(new SendWelcomeEmail('ada@example.com')));
        self::assertSame($invoice, $registry->policyFor(new GenerateInvoice('INV-1')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'an empty string' => [''];
        yield 'an unknown class' => ['App\\DoesNotExist'];
        yield 'a scalar type' => ['string'];
        yield 'an interface' => [CustomerMessage::class];
        yield 'an abstract class' => [PaymentMessage::class];
    }

    #[Test]
    #[DataProvider('invalidTypes')]
    public function it_rejects_a_type_no_message_has_as_its_exact_class(string $message): void
    {
        $registry = new MessageExecutionPolicyRegistry(self::policy());

        try {
            $registry->register($message, self::policy());
            self::fail('An invalid message type was accepted.');
        } catch (InvalidMessageTypeException $exception) {
            self::assertSame(['message' => $message], $exception->context);
        }
    }

    #[Test]
    public function it_rejects_a_second_policy_for_the_same_message_type(): void
    {
        $registry = new MessageExecutionPolicyRegistry(self::policy());
        $first = self::policy();
        $registry->register(SendWelcomeEmail::class, $first);

        try {
            $registry->register(SendWelcomeEmail::class, self::policy());
            self::fail('A second policy for the same message type was accepted.');
        } catch (DuplicateMessageExecutionPolicyException $exception) {
            self::assertSame(['message' => SendWelcomeEmail::class], $exception->context);
        }

        self::assertSame($first, $registry->policyFor(new SendWelcomeEmail('ada@example.com')));
    }

    #[Test]
    public function it_rejects_a_second_policy_for_the_same_message_type_spelled_differently(): void
    {
        $registry = new MessageExecutionPolicyRegistry(self::policy());
        $registry->register(SendWelcomeEmail::class, self::policy());

        try {
            $registry->register('\\' . strtoupper(SendWelcomeEmail::class), self::policy());
            self::fail('A second policy for the same message type was accepted.');
        } catch (DuplicateMessageExecutionPolicyException $exception) {
            self::assertSame(['message' => SendWelcomeEmail::class], $exception->context);
        }
    }

    #[Test]
    public function it_supports_the_documented_configuration(): void
    {
        $registry = new MessageExecutionPolicyRegistry(new MessageExecutionPolicy(
            retry: new AttemptsRetryPolicy(3),
            backoff: new NoBackoffPolicy(),
        ));

        $registry->register(
            SendWelcomeEmail::class,
            new MessageExecutionPolicy(
                retry: new AttemptsRetryPolicy(5),
                backoff: new ExponentialBackoffPolicy(
                    initialDuration: Duration::seconds(5),
                    maximumDuration: Duration::minutes(5),
                ),
            ),
        );

        $registry->register(
            TakePayment::class,
            new MessageExecutionPolicy(retry: new NeverRetryPolicy(), backoff: new NoBackoffPolicy()),
        );

        $welcome = $registry->policyFor(new SendWelcomeEmail('ada@example.com'));
        $payment = $registry->policyFor(new TakePayment('PAY-1'));
        $invoice = $registry->policyFor(new GenerateInvoice('INV-1'));

        self::assertInstanceOf(AttemptsRetryPolicy::class, $welcome->retry);
        self::assertSame(5, $welcome->retry->maxAttempts);
        self::assertInstanceOf(ExponentialBackoffPolicy::class, $welcome->backoff);
        self::assertInstanceOf(NeverRetryPolicy::class, $payment->retry);
        self::assertSame($registry->default, $invoice);
    }

    private static function policy(): MessageExecutionPolicy
    {
        return new MessageExecutionPolicy(new AttemptsRetryPolicy(3), new NoBackoffPolicy());
    }
}
