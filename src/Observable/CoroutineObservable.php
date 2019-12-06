<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\ReactiveX\Observable;

use Rx\Disposable\EmptyDisposable;
use Rx\DisposableInterface;
use Rx\Observable;
use Rx\ObserverInterface;
use Rx\SchedulerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

class CoroutineObservable extends Observable
{
    /**
     * @var array<Callable>
     */
    private $callables;

    /**
     * @var SchedulerInterface
     */
    private $scheduler;

    public function __construct($callables, SchedulerInterface $scheduler)
    {
        $this->scheduler = $scheduler;
        $this->callables = $callables;
    }

    protected function _subscribe(ObserverInterface $observer): DisposableInterface
    {
        coroutine::create(function () use ($observer) {
            $wg = new WaitGroup();
            $wg->add(count($this->callables));
            foreach ($this->callables as $callable) {
                Coroutine::create(function () use ($observer, $callable, &$wg) {
                    try {
                        $result = $callable();
                        $this->scheduler->schedule(function () use ($observer, $result) {
                            $observer->onNext($result);
                        });
                    } catch (\Throwable $throwable) {
                        $this->scheduler->schedule(function () use ($observer, $throwable) {
                            $observer->onError($throwable);
                        });
                    } finally {
                        $wg->done();
                    }
                });
            }

            $wg->wait();
            $this->scheduler->schedule(function () use ($observer) {
                $observer->onCompleted();
            });
        });

        return new EmptyDisposable();
    }
}
