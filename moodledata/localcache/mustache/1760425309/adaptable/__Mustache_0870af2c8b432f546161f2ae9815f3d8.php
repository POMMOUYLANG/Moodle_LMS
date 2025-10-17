<?php

class __Mustache_0870af2c8b432f546161f2ae9815f3d8 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('hasmenu');
        $buffer .= $this->sectionA153280e6cbc738ab5248784d5a2da32($context, $indent, $value);

        return $buffer;
    }

    private function sectionA153280e6cbc738ab5248784d5a2da32(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
<div class="section_action_menu bulk-hidden ms-auto" data-sectionid="{{id}}" data-region="sectionactionsmmenu">
    {{{menu}}}
</div>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '<div class="section_action_menu bulk-hidden ms-auto" data-sectionid="';
                $value = $this->resolveValue($context->find('id'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" data-region="sectionactionsmmenu">
';
                $buffer .= $indent . '    ';
                $value = $this->resolveValue($context->find('menu'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
