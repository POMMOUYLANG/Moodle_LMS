<?php

class __Mustache_3e2846bee33b9eeade64f1d71d348ed1 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        if ($parent = $this->mustache->loadPartial('core_form/element-template-inline')) {
            $context->pushBlockContext(array(
                'element' => array($this, 'blockD40ddcaa9a091efe7161c11f6c524392'),
            ));
            $buffer .= $parent->renderInternal($context, $indent);
            $context->popBlockContext();
        }

        return $buffer;
    }

    private function sectionE6922901afa7b60d3ce7403587f8d6c3(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{.}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->last(), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0762f0e0d927d6a8487ffbc9078580b3(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    autofocus aria-describedby="{{element.iderror}}"
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                    autofocus aria-describedby="';
                $value = $this->resolveValue($context->findDot('element.iderror'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    public function blockD40ddcaa9a091efe7161c11f6c524392($context)
    {
        $indent = $buffer = '';
        $value = $context->findDot('element.frozen');
        if (empty($value)) {
            
            $buffer .= $indent . '            <button
';
            $buffer .= $indent . '                class="btn
';
            $buffer .= $indent . '                ';
            $value = $context->findDot('element.customclassoverride');
            if (empty($value)) {
                
                $buffer .= 'btn-secondary';
            }
            $buffer .= '
';
            $buffer .= $indent . '                ';
            $value = $context->findDot('element.customclassoverride');
            $buffer .= $this->sectionE6922901afa7b60d3ce7403587f8d6c3($context, $indent, $value);
            $buffer .= '"
';
            $buffer .= $indent . '                name="';
            $value = $this->resolveValue($context->findDot('element.name'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '"
';
            $buffer .= $indent . '                id="';
            $value = $this->resolveValue($context->findDot('element.id'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '"
';
            $buffer .= $indent . '                type="button"
';
            $value = $context->find('error');
            $buffer .= $this->section0762f0e0d927d6a8487ffbc9078580b3($context, $indent, $value);
            $buffer .= $indent . '                ';
            $value = $this->resolveValue($context->findDot('element.attributes'), $context);
            $buffer .= ($value === null ? '' : $value);
            $buffer .= '
';
            $buffer .= $indent . '                >
';
            $buffer .= $indent . '                ';
            $value = $this->resolveValue($context->findDot('element.value'), $context);
            $buffer .= ($value === null ? '' : $value);
            $buffer .= '
';
            $buffer .= $indent . '            </button>
';
        }
    
        return $buffer;
    }
}
