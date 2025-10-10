<?php

class __Mustache_5e5681610d49f043470ab82f61031d78 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<span id="maincontent"></span>
';
        $value = $context->find('title');
        $buffer .= $this->sectionC5c86a021803fdc17853749b965bec7c($context, $indent, $value);
        $buffer .= $indent . '<div class="activity-header" data-for="page-activity-header">';
        $value = $context->find('completion');
        $buffer .= $this->section3d06d043ca23241d4e65e60ff056f6af($context, $indent, $value);
        $value = $context->find('description');
        $buffer .= $this->section755b0cd6188ba676be0eabb62fb274e5($context, $indent, $value);
        $buffer .= '</div>
';
        $value = $context->find('additional_items');
        $buffer .= $this->section910a88c56cf723c95501bc26997c8d63($context, $indent, $value);

        return $buffer;
    }

    private function sectionC5c86a021803fdc17853749b965bec7c(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <h2>{{{title}}}</h2>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <h2>';
                $value = $this->resolveValue($context->find('title'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '</h2>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section21485ddbf620161c42cce56fdf9efccf(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' overallaggregation, completion ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' overallaggregation, completion ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section3d06d043ca23241d4e65e60ff056f6af(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <span class="visually-hidden">{{#str}} overallaggregation, completion {{/str}}</span>
        {{{completion}}}
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= '
';
                $buffer .= $indent . '        <span class="visually-hidden">';
                $value = $context->find('str');
                $buffer .= $this->section21485ddbf620161c42cce56fdf9efccf($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '        ';
                $value = $this->resolveValue($context->find('completion'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section755b0cd6188ba676be0eabb62fb274e5(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="activity-description" id="intro">
            {{{description}}}
        </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="activity-description" id="intro">
';
                $buffer .= $indent . '            ';
                $value = $this->resolveValue($context->find('description'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '        </div>
';
                $buffer .= $indent . '    ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4edf690e2a4ac24d0c576a6184c12a89(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' additionalcustomnav, core ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' additionalcustomnav, core ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section910a88c56cf723c95501bc26997c8d63(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <nav aria-label="{{#str}} additionalcustomnav, core {{/str}}">
        {{> core/url_select}}
    </nav>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <nav aria-label="';
                $value = $context->find('str');
                $buffer .= $this->section4edf690e2a4ac24d0c576a6184c12a89($context, $indent, $value);
                $buffer .= '">
';
                if ($partial = $this->mustache->loadPartial('core/url_select')) {
                    $buffer .= $partial->renderInternal($context, $indent . '        ');
                }
                $buffer .= $indent . '    </nav>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
