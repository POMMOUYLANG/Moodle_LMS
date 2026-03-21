<?php

class __Mustache_84ee304027e8d6ba78adee4ccb191dac extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('title');
        $buffer .= $this->section92dcc6408a8714f3560d09e94d45b0e5($context, $indent, $value);
        $value = $context->find('description');
        $buffer .= $this->sectionD178bd13d9e5c005c41dc32e506ab07b($context, $indent, $value);

        return $buffer;
    }

    private function section92dcc6408a8714f3560d09e94d45b0e5(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <h3 class="main">{{{title}}}</h3>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <h3 class="main">';
                $value = $this->resolveValue($context->find('title'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '</h3>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionD178bd13d9e5c005c41dc32e506ab07b(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <div class="box generalbox formsettingheading">{{{descriptionformatted}}}</div>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <div class="box generalbox formsettingheading">';
                $value = $this->resolveValue($context->find('descriptionformatted'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
