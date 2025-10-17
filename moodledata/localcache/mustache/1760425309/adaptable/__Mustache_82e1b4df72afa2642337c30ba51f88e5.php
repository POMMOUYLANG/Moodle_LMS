<?php

class __Mustache_82e1b4df72afa2642337c30ba51f88e5 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<h2>';
        $value = $this->resolveValue($context->find('heading'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '</h2>
';
        $value = $this->resolveValue($context->find('courseinfobox'), $context);
        $buffer .= $indent . ($value === null ? '' : $value);
        $buffer .= '
';
        $value = $context->find('widgets');
        $buffer .= $this->sectionFb8e8ddc9ca3702110812af7d06781d6($context, $indent, $value);
        $buffer .= '
';
        $value = $context->find('widgets');
        if (empty($value)) {
            
            $buffer .= $indent . '    <div id="notice" class="box py-3 generalbox">
';
            $buffer .= $indent . '        ';
            $value = $this->resolveValue($context->find('message'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '
';
            $buffer .= $indent . '    </div>
';
            $buffer .= $indent . '    ';
            $value = $this->resolveValue($context->find('continuebutton'), $context);
            $buffer .= ($value === null ? '' : $value);
            $buffer .= '
';
        }

        return $buffer;
    }

    private function sectionFb8e8ddc9ca3702110812af7d06781d6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{{.}}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->last(), $context);
                $buffer .= $indent . ($value === null ? '' : $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
