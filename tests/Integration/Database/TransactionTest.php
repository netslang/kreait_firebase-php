<?php

declare(strict_types=1);

namespace Kreait\Firebase\Tests\Integration\Database;

use Kreait\Firebase\Database\Reference;
use Kreait\Firebase\Database\Transaction;
use Kreait\Firebase\Exception\Database\TransactionFailed;
use Kreait\Firebase\Tests\Integration\DatabaseTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @internal
 */
class TransactionTest extends DatabaseTestCase
{
    /** @var Reference */
    private $ref;

    protected function setUp()
    {
        $this->ref = self::$db->getReference(self::$refPrefix);
    }

    public function testAValueCanBeWritten()
    {
        $ref = $this->ref->getChild(__FUNCTION__);

        self::$db->runTransaction(static function (Transaction $transaction) use ($ref) {
            $transaction->snapshot($ref);

            $transaction->set($ref, 'new value');
        });

        $this->assertSame('new value', $ref->getValue());
    }

    public function testATransactionPreventsAChangeWhenTheRemoteHasChanged()
    {
        $firstRef = $this->ref->getChild(__FUNCTION__);
        $firstRef->set(['key' => 'value']);

        $this->expectException(TransactionFailed::class);

        self::$db->runTransaction(static function (Transaction $transaction) use ($firstRef) {
            // Register a transaction for the given reference
            $transaction->snapshot($firstRef);

            // Set the value without a transaction
            $firstRef->set('new value');

            // This should fail
            $transaction->set($firstRef, 'new value');
        });
    }

    public function testATransactionKeepsTrackOfMultipleReferences()
    {
        $firstRef = $this->ref->getChild(__FUNCTION__.'_first');
        $secondRef = $this->ref->getChild(__FUNCTION__.'_second');

        $this->expectException(TransactionFailed::class);

        self::$db->runTransaction(function (Transaction $transaction) use ($firstRef, $secondRef) {
            // Register a transaction for the given reference
            $firstSnapshot = $transaction->snapshot($firstRef);
            $secondSnapshot = $transaction->snapshot($secondRef);

            $firstCurrentValue = $firstSnapshot->getValue() ?: 0;
            $newFirstValue = ++$firstCurrentValue;

            $secondCurrentValue = $secondSnapshot->getValue() ?: 0;
            $newSecondValue = ++$secondCurrentValue;

            // Set the value without a transaction
            $firstRef->set($newFirstValue);
            $secondRef->set($newSecondValue);

            // A transactional "set" will now fail
            try {
                $transaction->set($firstRef, $newFirstValue);
                $this->fail('An exception should have been thrown');
            } catch (TransactionFailed $e) {
                // this is expected
            }

            $transaction->set($secondRef, $newSecondValue);
        });
    }

    public function testAValueCanBeDeleted()
    {
        $ref = $this->ref->getChild(__FUNCTION__);

        self::$db->runTransaction(static function (Transaction $transaction) use ($ref) {
            $transaction->snapshot($ref);

            $transaction->remove($ref);
        });

        $this->assertTrue($noExceptionHasBeenThrown = true);
    }

    public function testATransactionPreventsADeletionWhenTheRemoteHasChanged()
    {
        $ref = $this->ref->getChild(__FUNCTION__);
        $ref->set(['key' => 'value']);

        $this->expectException(TransactionFailed::class);

        self::$db->runTransaction(static function (Transaction $transaction) use ($ref) {
            // Register a transaction for the given reference
            $transaction->snapshot($ref);

            // Set the value without a transaction
            $ref->set('new value');

            // This should fail
            $transaction->remove($ref);
        });
    }

    public function testATransactionErrorContainsTheRequestAndResponse()
    {
        $ref = $this->ref->getChild(__FUNCTION__);
        $ref->set(['key' => 'value']);

        try {
            self::$db->runTransaction(static function (Transaction $transaction) use ($ref) {
                // Register a transaction for the given reference
                $transaction->snapshot($ref);

                // Set the value without a transaction
                $ref->set('new value');

                // This should fail
                $transaction->remove($ref);
            });
            $this->fail('An exception should have been thrown');
        } catch (TransactionFailed $e) {
            $this->assertInstanceOf(RequestInterface::class, $e->getRequest());
            $this->assertInstanceOf(ResponseInterface::class, $e->getResponse());
        } catch (Throwable $e) {
            $this->fail('A '.TransactionFailed::class.' should have been thrown');
        }
    }
}
