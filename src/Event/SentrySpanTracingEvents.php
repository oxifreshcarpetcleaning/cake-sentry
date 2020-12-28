<?php

namespace Connehito\CakeSentry\Event;

use Cake\Event\Event;
use Cake\Event\EventListenerInterface;
use Cake\ORM\Query;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

/**
 * Class SentrySpanTracingEvents
 *
 * @author Andy Hoffner <andy@oxifresh.com>
 * @package Connehito\CakeSentry\Events
 */
class SentrySpanTracingEvents implements EventListenerInterface
{
    protected $lastEventEnd;

    protected $lastContext;
    /**
     * implementedEvents
     *
     *
     * @return array
     */
    public function implementedEvents(): array
    {
        return [
            'Controller.initialize' => 'newSpan',
            'Controller.beforeRender' => 'newSpan',
            'Controller.beforeRedirect' => 'newSpan',
            'Controller.shutdown' => 'newSpan',
            'Authentication.afterIdentify' => 'newSpan',
            'Model.initialize' => 'newSpanModel',
            'Model.beforeMarshal' => 'newSpanModel',
            'Model.afterMarshal' => 'newSpanModel',
            'Model.beforeFind' => 'newSpanModel',
            'Model.buildValidator' => 'newSpanModel',
            'Model.buildRules' => 'newSpanModel',
            'Model.beforeRules' => 'newSpanModel',
            'Model.afterRules' => 'newSpanModel',
            'Model.beforeSave' => 'newSpanModel',
            'Model.afterSave' => 'newSpanModel',
            'Model.afterSaveCommit' => 'newSpanModel',
            'Model.beforeDelete' => 'newSpanModel',
            'Model.afterDelete' => 'newSpanModel',
            'Model.afterDeleteCommit' => 'newSpanModel'
        ];
    }
    /**
     * newSpan
     *
     * @param Event $event
     *
     */
    public function newSpan(Event $event)
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // Events are triggered at their start but we won't know the end time until the next event fires.
        // So keep track of the last context, and when we start a new event, record that it just now ended.
        if(!empty($this->lastContext)){
            $this->lastContext->setEndTimestamp(microtime(true));
            $parentSpan->startChild($this->lastContext);
        }

        $context = new SpanContext();
        $context->setOp($event->getName());
        $context->setDescription('Triggered by ' . get_class($event->getSubject()));
        $context->setStartTimestamp(microtime(true));

        $this->lastContext = $context;

//        $context->setStartTimestamp(($this->lastEventEnd ?? $_SERVER['REQUEST_TIME_FLOAT'] ?? 0) / 1000);
//        $this->lastEventEnd = microtime(true) / 1000;
//        $context->setEndTimestamp($this->lastEventEnd);


    }
    
    public function newSpanModel(Event $event){
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // Events are triggered at their start but we won't know the end time until the next event fires.
        // So keep track of the last context, and when we start a new event, record that it just now ended.
        if(!empty($this->lastContext)){
            $this->lastContext->setEndTimestamp(microtime(true));
            $parentSpan->startChild($this->lastContext);
        }

        $context = new SpanContext();
        $context->setOp($event->getName());
        $context->setDescription('Triggered by ' . get_class($event->getSubject()));
        $context->setStartTimestamp(microtime(true));

        if($event->getData()){
            $query = '';
            foreach($event->getData() as $data){
                if($data instanceof Query){
                    $context->setData([
                        'query' => $data->sql()
                    ]);
                }
            }
        }

        $this->lastContext = $context;
    }
}