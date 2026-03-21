<?php

class __Mustache_a86b10c201ddbc5d627eba37d7039587 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        if ($parent = $this->mustache->loadPartial('core_form/element-group')) {
            $context->pushBlockContext(array(
                'element' => array($this, 'block24fa380649e4e222c1d86518633a6933'),
            ));
            $buffer .= $parent->renderInternal($context, $indent);
            $context->popBlockContext();
        }

        return $buffer;
    }

    private function section86579468c813d19c6be8d55491ef4e7d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                {{{separator}}}
                {{{html}}}
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                ';
                $value = $this->resolveValue($context->find('separator'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '                ';
                $value = $this->resolveValue($context->find('html'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    public function block24fa380649e4e222c1d86518633a6933($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . '        <fieldset data-fieldtype="date" class="m-0 p-0 border-0" id="';
        $value = $this->resolveValue($context->findDot('element.id'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '            <legend class="visually-hidden">';
        $value = $this->resolveValue($context->find('label'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '</legend>
';
        $buffer .= $indent . '            <span class="fdate_selector d-flex flex-wrap align-items-center">
';
        $value = $context->findDot('element.elements');
        $buffer .= $this->section86579468c813d19c6be8d55491ef4e7d($context, $indent, $value);
        $buffer .= $indent . '            </span>
';
        $buffer .= $indent . '        </fieldset>
';
    
        return $buffer;
    }
}
