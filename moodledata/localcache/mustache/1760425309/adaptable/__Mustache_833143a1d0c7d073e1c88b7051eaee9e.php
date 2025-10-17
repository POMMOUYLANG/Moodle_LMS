<?php

class __Mustache_833143a1d0c7d073e1c88b7051eaee9e extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div
';
        $buffer .= $indent . '    class="activity-icon activityiconcontainer ';
        $value = $this->resolveValue($context->find('purpose'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= ' ';
        $value = $context->find('branded');
        $buffer .= $this->sectionA8036d43d69969e19aac7ce50fe51af1($context, $indent, $value);
        $buffer .= ' ';
        $value = $this->resolveValue($context->find('extraclasses'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '>
';
        $buffer .= $indent . '    <img
';
        $buffer .= $indent . '        src="';
        $value = $this->resolveValue($context->find('icon'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '"
';
        $buffer .= $indent . '        class="activityicon icon ';
        $value = $this->resolveValue($context->find('iconclass'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '        data-region="activity-icon"
';
        $buffer .= $indent . '        alt="';
        $value = $context->find('title');
        $buffer .= $this->section0b13c1fa65051c55347b343c829e81c5($context, $indent, $value);
        $buffer .= '"
';
        $buffer .= $indent . '        ';
        $value = $context->find('title');
        $buffer .= $this->section4b8b1d056248164fd07f210141302f45($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '    >
';
        $buffer .= $indent . '</div>
';

        return $buffer;
    }

    private function sectionA8036d43d69969e19aac7ce50fe51af1(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'isbranded';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'isbranded';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section5fb8f4873e24cc08353dc61cdb42dc9f(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' activityicon, moodle, {{{pluginname}}} ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' activityicon, moodle, ';
                $value = $this->resolveValue($context->find('pluginname'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= ' ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0b13c1fa65051c55347b343c829e81c5(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{#cleanstr}} activityicon, moodle, {{{pluginname}}} {{/cleanstr}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('cleanstr');
                $buffer .= $this->section5fb8f4873e24cc08353dc61cdb42dc9f($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4b8b1d056248164fd07f210141302f45(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'title="{{{title}}}"';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'title="';
                $value = $this->resolveValue($context->find('title'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '"';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
