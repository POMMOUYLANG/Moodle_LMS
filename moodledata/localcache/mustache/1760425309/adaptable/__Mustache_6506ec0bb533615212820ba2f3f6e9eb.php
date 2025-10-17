<?php

class __Mustache_6506ec0bb533615212820ba2f3f6e9eb extends Mustache_Template
{
    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $buffer = '';

        $buffer .= $indent . '<div class="secondary-navigation align-self-center">
';
        if ($partial = $this->mustache->loadPartial('core/moremenu')) {
            $buffer .= $partial->renderInternal($context, $indent . '    ');
        }
        $buffer .= $indent . '</div>
';

        return $buffer;
    }
}
