<?php

class __Mustache_43edf2512f18e66729e5f6b0bfeaab14 extends Mustache_Template
{
    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $buffer = '';

        $buffer .= $indent . '<div class="headersearch ';
        $value = $this->resolveValue($context->find('pagelayout'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= ' d-none d-lg-inline-block">
';
        $buffer .= $indent . '    ';
        $value = $this->resolveValue($context->find('search'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '</div>
';

        return $buffer;
    }
}
