<?php
/**
 * This file is part of the prooph/humus-amqp-producer.
 * (c) 2016-2017 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2017 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\ServiceBus\Message\HumusAmqp;

use Humus\Amqp\Producer;
use PHPUnit_Framework_TestCase as TestCase;
use Prooph\Common\Event\ActionEvent;
use Prooph\Common\Event\ActionEventEmitter;
use Prooph\Common\Event\DefaultActionEvent;
use Prooph\Common\Event\ListenerHandler;
use Prooph\EventStore\ActionEventEmitterEventStore;
use Prooph\EventStore\EventStore;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Message\HumusAmqp\ConfirmSelectEventPublisher;
use Prooph\ServiceBus\Plugin\Router\EventRouter;
use Prophecy\Argument;

class ConfirmSelectEventPublisherTest extends TestCase
{
    /**
     * @test
     */
    public function it_confirms_select_and_waits_for_confirm_on_event_store_commit_post()
    {
        $actionEvent = $this->prophesize(ActionEvent::class);
        $iterator = new \ArrayIterator(['foo', 'bar']);
        $actionEvent->getParam('recordedEvents', new \ArrayIterator())->willReturn($iterator)->shouldBeCalled();

        $eventBus = $this->prophesize(EventBus::class);
        $eventBus->dispatch('foo')->shouldBeCalled();
        $eventBus->dispatch('bar')->shouldBeCalled();

        $producer = $this->prophesize(Producer::class);
        $producer->confirmSelect()->shouldBeCalled();
        $producer->setConfirmCallback(Argument::type('callable'), Argument::type('callable'))->shouldBeCalled();
        $producer->waitForConfirm(2.0)->shouldBeCalled();

        $plugin = new ConfirmSelectEventPublisher($eventBus->reveal(), $producer->reveal(), 2.0);
        $plugin->onEventStoreCommitPost($actionEvent->reveal());
    }

    /**
     * @test
     */
    public function it_confirms_select_one_action_event_after_the_other()
    {
        $actionEvent = $this->prophesize(ActionEvent::class);
        $iterator = new \ArrayIterator(['foo', 'bar']);
        $actionEvent->getParam('recordedEvents', new \ArrayIterator())->willReturn($iterator)->shouldBeCalled();

        $producer = $this->prophesize(Producer::class);
        $producer->confirmSelect()->shouldBeCalledTimes(2);
        $producer->setConfirmCallback(Argument::type('callable'), Argument::type('callable'))->shouldBeCalledTimes(2);
        $producer->waitForConfirm(2.0)->shouldBeCalledTimes(2);

        $eventBus = new EventBus();

        $plugin = new ConfirmSelectEventPublisher($eventBus, $producer->reveal(), 2.0);

        $eventBusCalls = [];

        $eventRouter = new EventRouter();
        $eventRouter->route('foo')->to(function ($event) use ($plugin, &$eventBusCalls) {
            $eventBusCalls[] = $event;
            $actionEvent = new DefaultActionEvent($event, null, [
                'recordedEvents' => new \ArrayIterator(['baz', 'bam', 'bat']),
            ]);
            $plugin->onEventStoreCommitPost($actionEvent);
        });

        $eventRouter->route('bar')->to(function ($event) use (&$eventBusCalls) {
            $eventBusCalls[] = $event;
        });
        $eventRouter->route('baz')->to(function ($event) use (&$eventBusCalls) {
            $eventBusCalls[] = $event;
        });
        $eventRouter->route('bam')->to(function ($event) use (&$eventBusCalls) {
            $eventBusCalls[] = $event;
        });
        $eventRouter->route('bat')->to(function ($event) use (&$eventBusCalls) {
            $eventBusCalls[] = $event;
        });

        $eventRouter->attachToMessageBus($eventBus);

        $plugin->onEventStoreCommitPost($actionEvent->reveal());

        $this->assertEquals(
            [
                'foo',
                'bar',
                'baz',
                'bam',
                'bat',
            ],
            $eventBusCalls
        );
    }

    /**
     * @test
     */
    public function it_does_nothing_when_no_recorded_events()
    {
        $actionEvent = new DefaultActionEvent('name');

        $eventBus = $this->prophesize(EventBus::class);
        $eventBus->dispatch(Argument::any())->shouldNotBeCalled();

        $producer = $this->prophesize(Producer::class);
        $producer->confirmSelect()->shouldNotBeCalled();
        $producer->setConfirmCallback(Argument::type('callable'), Argument::type('callable'))->shouldNotBeCalled();
        $producer->waitForConfirm(2)->shouldNotBeCalled();

        $plugin = new ConfirmSelectEventPublisher($eventBus->reveal(), $producer->reveal(), 2.0);
        $plugin->onEventStoreCommitPost($actionEvent);
    }
}
