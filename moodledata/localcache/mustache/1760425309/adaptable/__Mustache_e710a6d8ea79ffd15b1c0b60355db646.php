<?php

class __Mustache_e710a6d8ea79ffd15b1c0b60355db646 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('isInteractive');
        $buffer .= $this->section2bb6a8b2d382693bcaaf1c23dac2a189($context, $indent, $value);
        $value = $context->find('isInteractive');
        if (empty($value)) {
            
            $buffer .= $indent . '    <div
';
            $buffer .= $indent . '    data-region="groupmode-information"
';
            $buffer .= $indent . '    data-activityname="';
            $value = $this->resolveValue($context->find('activityname'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '"
';
            $buffer .= $indent . '    class="groupmode-information d-flex align-items-center justify-content-center icon-no-margin"
';
            $buffer .= $indent . '>
';
            $buffer .= $indent . '    ';
            $value = $context->find('groupicon');
            $buffer .= $this->sectionBe67a45eb42e98accc75b5c263d6b289($context, $indent, $value);
            $buffer .= '
';
            $value = $context->find('groupalt');
            $buffer .= $this->section118f05228751b6b1601f125cb1cd4f90($context, $indent, $value);
            $buffer .= $indent . '</div>
';
        }

        return $buffer;
    }

    private function section32db0b198771114975407fc1b32ded20(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' v-parent-focus ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' v-parent-focus ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section254d49337957571f3831bf3d9cf7419e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        {{< core/local/dropdown/status}}
            {{$ buttonclasses }}
                {{#autohide}} v-parent-focus {{/autohide}}
                groupmode-information btn btn-icon
            {{/ buttonclasses }}
            {{$ buttoncontent }}
                {{{groupicon}}}
                <span class="groupmode-icon-info">{{groupalt}}</span>
            {{/ buttoncontent }}
        {{/ core/local/dropdown/status}}
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        ';
                if ($parent = $this->mustache->loadPartial('core/local/dropdown/status')) {
                    $context->pushBlockContext(array(
                        'buttonclasses' => array($this, 'block0117cf4716ac939552ec3b5ef0e54efa'),
                        'buttoncontent' => array($this, 'block4bc58dd494a134f9053a9086dc3450ac'),
                    ));
                    $buffer .= $parent->renderInternal($context, $indent);
                    $context->popBlockContext();
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2bb6a8b2d382693bcaaf1c23dac2a189(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    {{#dropwdown}}
        {{< core/local/dropdown/status}}
            {{$ buttonclasses }}
                {{#autohide}} v-parent-focus {{/autohide}}
                groupmode-information btn btn-icon
            {{/ buttonclasses }}
            {{$ buttoncontent }}
                {{{groupicon}}}
                <span class="groupmode-icon-info">{{groupalt}}</span>
            {{/ buttoncontent }}
        {{/ core/local/dropdown/status}}
    {{/dropwdown}}
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('dropwdown');
                $buffer .= $this->section254d49337957571f3831bf3d9cf7419e($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionBe67a45eb42e98accc75b5c263d6b289(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{{groupicon}}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('groupicon'), $context);
                $buffer .= ($value === null ? '' : $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section118f05228751b6b1601f125cb1cd4f90(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="groupmode-icon-info ms-1">{{groupalt}}</div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="groupmode-icon-info ms-1">';
                $value = $this->resolveValue($context->find('groupalt'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    public function block0117cf4716ac939552ec3b5ef0e54efa($context)
    {
        $indent = $buffer = '';
        $buffer .= '                ';
        $value = $context->find('autohide');
        $buffer .= $this->section32db0b198771114975407fc1b32ded20($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '                groupmode-information btn btn-icon
';
    
        return $buffer;
    }

    public function block4bc58dd494a134f9053a9086dc3450ac($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . '                ';
        $value = $this->resolveValue($context->find('groupicon'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '                <span class="groupmode-icon-info">';
        $value = $this->resolveValue($context->find('groupalt'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '</span>
';
    
        return $buffer;
    }
}
