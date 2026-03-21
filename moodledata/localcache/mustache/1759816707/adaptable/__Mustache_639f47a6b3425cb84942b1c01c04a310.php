<?php

class __Mustache_639f47a6b3425cb84942b1c01c04a310 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        if ($parent = $this->mustache->loadPartial('core_form/element-template')) {
            $context->pushBlockContext(array(
                'label' => array($this, 'blockF7734b580e631e6eeb9ef04b45ac8cee'),
                'element' => array($this, 'block2dd66a3c73e62b698dbe3bb731456089'),
            ));
            $buffer .= $parent->renderInternal($context, $indent);
            $context->popBlockContext();
        }
        $value = $context->find('js');
        $buffer .= $this->section224f8c1b5b0d422962eefed51c5243ac($context, $indent, $value);

        return $buffer;
    }

    private function section224f8c1b5b0d422962eefed51c5243ac(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
(function() {
    var label = document.getElementById(\'{{element.id}}_label\');
    if (label) {
        label.style.cursor = \'default\';
        label.addEventListener(\'click\', function() {
            document.querySelectorAll(\'#{{element.id}}_fieldset div.fp-toolbar a\')[0].focus();
        });
    }
})();
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '(function() {
';
                $buffer .= $indent . '    var label = document.getElementById(\'';
                $value = $this->resolveValue($context->findDot('element.id'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '_label\');
';
                $buffer .= $indent . '    if (label) {
';
                $buffer .= $indent . '        label.style.cursor = \'default\';
';
                $buffer .= $indent . '        label.addEventListener(\'click\', function() {
';
                $buffer .= $indent . '            document.querySelectorAll(\'#';
                $value = $this->resolveValue($context->findDot('element.id'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '_fieldset div.fp-toolbar a\')[0].focus();
';
                $buffer .= $indent . '        });
';
                $buffer .= $indent . '    }
';
                $buffer .= $indent . '})();
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    public function blockF7734b580e631e6eeb9ef04b45ac8cee($context)
    {
        $indent = $buffer = '';
        $value = $context->findDot('element.hiddenlabel');
        if (empty($value)) {
            
            $buffer .= $indent . '            <p id="';
            $value = $this->resolveValue($context->findDot('element.id'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '_label" class="mb-0 d-inline" aria-hidden="true">
';
            $buffer .= $indent . '                ';
            $value = $this->resolveValue($context->find('label'), $context);
            $buffer .= ($value === null ? '' : $value);
            $buffer .= '
';
            $buffer .= $indent . '            </p>
';
        }
    
        return $buffer;
    }

    public function block2dd66a3c73e62b698dbe3bb731456089($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . '        <fieldset class="w-100 m-0 p-0 border-0" id="';
        $value = $this->resolveValue($context->findDot('element.id'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '_fieldset">
';
        $buffer .= $indent . '            <legend class="visually-hidden">';
        $value = $this->resolveValue($context->find('label'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '</legend>
';
        $buffer .= $indent . '            ';
        $value = $this->resolveValue($context->findDot('element.html'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '        </fieldset>
';
    
        return $buffer;
    }
}
