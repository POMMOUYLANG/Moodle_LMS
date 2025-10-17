<?php

class __Mustache_e4c676a582a7ccdb79425b502a4d1913 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $this->resolveValue($context->find('courseshortname'), $context);
        $buffer .= $indent . ($value === null ? '' : $value);
        $buffer .= ' -> ';
        $value = $context->find('str');
        $buffer .= $this->section768487210e294cb436ed4460c3750f61($context, $indent, $value);
        $buffer .= ' -> ';
        $value = $this->resolveValue($context->find('assignment'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '---------------------------------------------------------------------
';
        $value = $this->resolveValue($context->find('messagetext'), $context);
        $buffer .= $indent . ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '
';
        $buffer .= $indent . '---------------------------------------------------------------------
';

        return $buffer;
    }

    private function section768487210e294cb436ed4460c3750f61(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'modulename, mod_assign';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'modulename, mod_assign';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
