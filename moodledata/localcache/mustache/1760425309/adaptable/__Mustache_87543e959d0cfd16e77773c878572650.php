<?php

class __Mustache_87543e959d0cfd16e77773c878572650 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<form method="dialog">
';
        if ($partial = $this->mustache->loadPartial('core/datafilter/filter')) {
            $buffer .= $partial->renderInternal($context);
        }
        $buffer .= $indent . '</form>
';
        $buffer .= $indent . '
';
        $value = $context->find('js');
        $buffer .= $this->section1cc5d2fd9fe454f1c03fb462bb60361d($context, $indent, $value);

        return $buffer;
    }

    private function section1cc5d2fd9fe454f1c03fb462bb60361d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    require([\'core_user/participants_filter\'], function(ParticipantsFilter) {
        ParticipantsFilter.init(\'core-filter-{{uniqid}}\');
    });
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    require([\'core_user/participants_filter\'], function(ParticipantsFilter) {
';
                $buffer .= $indent . '        ParticipantsFilter.init(\'core-filter-';
                $value = $this->resolveValue($context->find('uniqid'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '\');
';
                $buffer .= $indent . '    });
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
