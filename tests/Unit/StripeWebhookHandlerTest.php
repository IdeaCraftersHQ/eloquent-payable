<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

require_once __DIR__ . '/TestCaseHelpers.php';

use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Ideacrafters\EloquentPayable\Processors\ProcessorNames;
use Ideacrafters\EloquentPayable\Processors\StripeProcessor;
use Ideacrafters\EloquentPayable\Processors\StripeWebhookHandler;
use Ideacrafters\EloquentPayable\Tests\TestCase;
use Ideacrafters\EloquentPayable\Tests\Unit\TestUser;
use Ideacrafters\EloquentPayable\Tests\Unit\TestPayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionClass;

class StripeWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        EventFacade::fake();

        // Set webhook secret for testing
        Config::set('payable.stripe.webhook_secret', 'test_webhook_secret');
        Config::set('payable.webhooks.event_idempotency_ttl_days', 30);

        // Create test tables for relationships
        \Illuminate\Support\Facades\Schema::create('test_payers', function ($table) {
            $table->id();
        });

        \Illuminate\Support\Facades\Schema::create('test_payables', function ($table) {
            $table->id();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Create a mock Stripe event object.
     * Uses stdClass to avoid Stripe object internal method conflicts.
     */
    protected function createMockEvent(string $eventType, string $eventId, $dataObject)
    {
        $event = new \stdClass();
        $event->id = $eventId;
        $event->type = $eventType;
        $event->data = (object) ['object' => $dataObject];
        
        return $event;
    }

    /**
     * Create a mock payment intent.
     * Uses stdClass to avoid Stripe object internal method conflicts.
     */
    protected function createMockPaymentIntent(string $id, string $status = 'succeeded')
    {
        $paymentIntent = new \stdClass();
        $paymentIntent->id = $id;
        $paymentIntent->status = $status;
        $paymentIntent->last_payment_error = null;
        
        return $paymentIntent;
    }


    /** @test */
    public function it_resolves_existing_handler_methods()
    {
        $handler = new StripeWebhookHandler();
        $reflection = new ReflectionClass($handler);
        $resolveMethod = $reflection->getMethod('resolveEventHandler');
        $resolveMethod->setAccessible(true);

        // Test that all existing handlers in StripeWebhookHandler are resolved
        $existingHandlers = [
            'payment_intent.succeeded' => 'handlePaymentIntentSucceeded',
            'payment_intent.payment_failed' => 'handlePaymentIntentPaymentFailed',
            'payment_intent.canceled' => 'handlePaymentIntentCanceled',
            'payment_intent.updated' => 'handlePaymentIntentUpdated',
        ];

        foreach ($existingHandlers as $eventType => $expectedMethodName) {
            $resolvedMethod = $resolveMethod->invoke($handler, $eventType);
            $this->assertEquals(
                $expectedMethodName,
                $resolvedMethod,
                "Event type '{$eventType}' should resolve to method '{$expectedMethodName}'"
            );
        }
    }

    /** @test */
    public function it_resolves_custom_handler_methods_in_extended_class()
    {
        $extendedHandler = new TestableStripeWebhookHandler();
        $reflection = new ReflectionClass($extendedHandler);
        $resolveMethod = $reflection->getMethod('resolveEventHandler');
        $resolveMethod->setAccessible(true);

        // Test that custom handlers in the extended class are resolved
        $customHandlers = [
            'checkout.session.completed' => 'handleCheckoutSessionCompleted',
            'invoice.created' => 'handleInvoiceCreated',
        ];

        foreach ($customHandlers as $eventType => $expectedMethodName) {
            $resolvedMethod = $resolveMethod->invoke($extendedHandler, $eventType);
            $this->assertEquals(
                $expectedMethodName,
                $resolvedMethod,
                "Event type '{$eventType}' should resolve to custom method '{$expectedMethodName}' in extended class"
            );
        }
    }

    /** @test */
    public function it_throws_exception_for_unhandled_events()
    {
        $handler = new StripeWebhookHandler();
        $reflection = new ReflectionClass($handler);
        $resolveMethod = $reflection->getMethod('resolveEventHandler');
        $resolveMethod->setAccessible(true);

        // Test that unhandled events throw PaymentException
        $unhandledEvents = [
            'checkout.session.completed',
            'invoice.created',
            'customer.subscription.deleted',
        ];

        foreach ($unhandledEvents as $eventType) {
            $expectedMethod = 'handle' . \Illuminate\Support\Str::studly(str_replace('.', '_', $eventType));
            $className = StripeWebhookHandler::class;
            
            $this->expectException(PaymentException::class);
            $this->expectExceptionMessage("Stripe webhook event '{$eventType}' has no handler method");
            $this->expectExceptionMessage("Create a '{$expectedMethod}()' method in the {$className} class to handle this event");
            
            $resolveMethod->invoke($handler, $eventType);
        }
    }

    /** @test */
    public function it_includes_extended_class_name_in_exception_message()
    {
        $extendedHandler = new TestableStripeWebhookHandler();
        $reflection = new ReflectionClass($extendedHandler);
        $resolveMethod = $reflection->getMethod('resolveEventHandler');
        $resolveMethod->setAccessible(true);

        // Test that exception message includes the extended class name, not the base class
        $eventType = 'customer.subscription.deleted';
        $expectedMethod = 'handle' . \Illuminate\Support\Str::studly(str_replace('.', '_', $eventType));
        $extendedClassName = TestableStripeWebhookHandler::class;
        
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage("Stripe webhook event '{$eventType}' has no handler method");
        $this->expectExceptionMessage("Create a '{$expectedMethod}()' method in the {$extendedClassName} class to handle this event");
        
        $resolveMethod->invoke($extendedHandler, $eventType);
    }

    /** @test */
    public function extended_processor_uses_extended_webhook_handler()
    {
        // Create an extended processor that uses an extended webhook handler
        $extendedProcessor = new class extends StripeProcessor {
            protected function createWebhookHandler(): StripeWebhookHandler
            {
                return new TestableStripeWebhookHandler();
            }
        };

        // Use reflection to access the protected webhookHandler property
        $reflection = new ReflectionClass($extendedProcessor);
        $webhookHandlerProperty = $reflection->getProperty('webhookHandler');
        $webhookHandlerProperty->setAccessible(true);
        $webhookHandler = $webhookHandlerProperty->getValue($extendedProcessor);

        // Verify that the extended processor is using the extended webhook handler
        $this->assertInstanceOf(TestableStripeWebhookHandler::class, $webhookHandler);
        // Verify it's the exact class, not just the base class
        $this->assertEquals(TestableStripeWebhookHandler::class, get_class($webhookHandler));
    }

    /** @test */
    public function base_processor_uses_base_webhook_handler()
    {
        $processor = new StripeProcessor();

        // Use reflection to access the protected webhookHandler property
        $reflection = new ReflectionClass($processor);
        $webhookHandlerProperty = $reflection->getProperty('webhookHandler');
        $webhookHandlerProperty->setAccessible(true);
        $webhookHandler = $webhookHandlerProperty->getValue($processor);

        // Verify that the base processor uses the base webhook handler
        $this->assertInstanceOf(StripeWebhookHandler::class, $webhookHandler);
    }

    /** @test */
    public function processor_uses_service_container_bound_webhook_handler()
    {
        // Users can rebind the webhook handler in their service provider (Laravel way)
        $this->app->singleton(StripeWebhookHandler::class, function () {
            return new TestableStripeWebhookHandler();
        });

        $processor = new StripeProcessor();

        // Use reflection to access the protected webhookHandler property
        $reflection = new ReflectionClass($processor);
        $webhookHandlerProperty = $reflection->getProperty('webhookHandler');
        $webhookHandlerProperty->setAccessible(true);
        $webhookHandler = $webhookHandlerProperty->getValue($processor);

        // Verify that the processor is using the rebound webhook handler
        $this->assertInstanceOf(TestableStripeWebhookHandler::class, $webhookHandler);
        $this->assertEquals(TestableStripeWebhookHandler::class, get_class($webhookHandler));
    }

}

/**
 * Testable version of StripeWebhookHandler with custom handlers
 * for testing method discovery and extensibility.
 */
class TestableStripeWebhookHandler extends StripeWebhookHandler
{
    /**
     * Custom handler for checkout.session.completed event.
     */
    protected function handleCheckoutSessionCompleted($checkoutSession)
    {
        // Custom handler implementation for testing
        return 'checkout_session_completed';
    }

    /**
     * Custom handler for invoice.created event.
     */
    protected function handleInvoiceCreated($invoice)
    {
        // Custom handler implementation for testing
        return 'invoice_created';
    }
}


