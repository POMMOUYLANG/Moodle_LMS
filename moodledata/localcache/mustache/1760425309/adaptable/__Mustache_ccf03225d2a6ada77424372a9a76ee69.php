<?php

class __Mustache_ccf03225d2a6ada77424372a9a76ee69 extends Mustache_Template
{
    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $buffer = '';

        $buffer .= $indent . '<div class="form-item row">
';
        $buffer .= $indent . '    <div class="form-label col-sm-3 text-sm-end">
';
        $buffer .= $indent . '        <label>
';
        $buffer .= $indent . '            ';
        $value = $this->resolveValue($context->find('title'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '        </label>
';
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '    <div class="form-setting col-sm-9">
';
        $buffer .= $indent . '        <div class="form-text">';
        $value = $this->resolveValue($context->find('description'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</div>
';
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '</div>
';

        return $buffer;
    }
}
