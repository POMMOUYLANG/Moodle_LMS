<?php

class __Mustache_4b550902eb98d67a905676e192325d34 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<p><font face="sans-serif">
';
        $buffer .= $indent . '    <a href="';
        $value = $this->resolveValue($context->find('courseurl'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">';
        $value = $this->resolveValue($context->find('courseshortname'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</a> ->
';
        $buffer .= $indent . '    <a href="';
        $value = $this->resolveValue($context->find('courseassignsurl'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">';
        $value = $context->find('str');
        $buffer .= $this->section768487210e294cb436ed4460c3750f61($context, $indent, $value);
        $buffer .= '</a> ->
';
        $buffer .= $indent . '    <a href="';
        $value = $this->resolveValue($context->find('url'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">';
        $value = $this->resolveValue($context->find('assignment'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</a>
';
        $buffer .= $indent . '</font></p>
';
        $buffer .= $indent . '<hr>
';
        $buffer .= $indent . '<font face="sans-serif"><p>';
        $value = $this->resolveValue($context->find('messagehtml'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</p></font>
';
        $buffer .= $indent . '<hr>
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
