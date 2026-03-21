<?php

class __Mustache_4da0be52d71d2170e9ccc871084007ee extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div class="form-filetypes-descriptions w-100">
';
        $value = $context->find('hasdescriptions');
        $buffer .= $this->sectionC72152d17ca6368d81b5888469cfc4c3($context, $indent, $value);
        $value = $context->find('hasdescriptions');
        if (empty($value)) {
            
            $buffer .= $indent . '    <p>';
            $value = $context->find('str');
            $buffer .= $this->section30129dc0794ae04b28e7bc4b7f11d4d6($context, $indent, $value);
            $buffer .= '</p>
';
        }
        $buffer .= $indent . '</div>
';

        return $buffer;
    }

    private function sectionC25cc744722b5dbd6ea0d68b01372c5a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <li>{{description}} <small class="text-dark">{{extensions}}</small></li>
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <li>';
                $value = $this->resolveValue($context->find('description'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ' <small class="text-dark">';
                $value = $this->resolveValue($context->find('extensions'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</small></li>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC72152d17ca6368d81b5888469cfc4c3(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <ul class="list-unstyled unstyled">
        {{#descriptions}}
        <li>{{description}} <small class="text-dark">{{extensions}}</small></li>
        {{/descriptions}}
    </ul>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <ul class="list-unstyled unstyled">
';
                $value = $context->find('descriptions');
                $buffer .= $this->sectionC25cc744722b5dbd6ea0d68b01372c5a($context, $indent, $value);
                $buffer .= $indent . '    </ul>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section30129dc0794ae04b28e7bc4b7f11d4d6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'noselection, form';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'noselection, form';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
