<?php

class __Mustache_6b52ba115678f7e1f38678da90a58979 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('editing');
        $buffer .= $this->sectionBbf11d1876fbab900045a7009456c8b7($context, $indent, $value);
        $value = $context->find('editing');
        if (empty($value)) {
            
            $value = $context->find('hiddenfromstudents');
            $buffer .= $this->sectionB88fc8d3aac9f425bb16253c35db2d95($context, $indent, $value);
            $value = $context->find('notavailable');
            $buffer .= $this->section5a7f022ea4dc1bd204a4c48ff2b8dc1a($context, $indent, $value);
        }

        return $buffer;
    }

    private function sectionA7dc7f077cff6b19650389e05ddec6e0(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            {{< core/local/dropdown/status}}
                {{$ buttonclasses }} badge rounded-pill bg-secondary text-dark dropdown-toggle border-0{{/ buttonclasses }}
            {{/ core/local/dropdown/status}}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            ';
                if ($parent = $this->mustache->loadPartial('core/local/dropdown/status')) {
                    $context->pushBlockContext(array(
                        'buttonclasses' => array($this, 'blockEf09851a04fea3a91c898b8db7b03577'),
                    ));
                    $buffer .= $parent->renderInternal($context, $indent);
                    $context->popBlockContext();
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section31ae0fbdc439c6d3e86010ee0fe2597e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'notavailable';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'notavailable';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionBbf11d1876fbab900045a7009456c8b7(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <div class="order-2" data-region="sectionvisibility">
        {{#dropwdown}}
            {{< core/local/dropdown/status}}
                {{$ buttonclasses }} badge rounded-pill bg-secondary text-dark dropdown-toggle border-0{{/ buttonclasses }}
            {{/ core/local/dropdown/status}}
        {{/dropwdown}}
    </div>
    <span class="badge rounded-pill bg-secondary text-dark order-3 {{^notavailable}}d-none{{/notavailable}}">
        {{#str}}notavailable{{/str}}
    </span>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <div class="order-2" data-region="sectionvisibility">
';
                $value = $context->find('dropwdown');
                $buffer .= $this->sectionA7dc7f077cff6b19650389e05ddec6e0($context, $indent, $value);
                $buffer .= '    </div>
';
                $buffer .= $indent . '    <span class="badge rounded-pill bg-secondary text-dark order-3 ';
                $value = $context->find('notavailable');
                if (empty($value)) {
                    
                    $buffer .= 'd-none';
                }
                $buffer .= '">
';
                $buffer .= $indent . '        ';
                $value = $context->find('str');
                $buffer .= $this->section31ae0fbdc439c6d3e86010ee0fe2597e($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '    </span>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section6a6391930071a7b8ee93dbc237b86962(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'i/show, core';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'i/show, core';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionAa413acb7776474efcb52c4cbf9fc6f9(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'hiddenfromstudents';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'hiddenfromstudents';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionB88fc8d3aac9f425bb16253c35db2d95(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <span class="badge rounded-pill bg-secondary text-dark order-2">{{#pix}}i/show, core{{/pix}}{{#str}}hiddenfromstudents{{/str}}</span>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <span class="badge rounded-pill bg-secondary text-dark order-2">';
                $value = $context->find('pix');
                $buffer .= $this->section6a6391930071a7b8ee93dbc237b86962($context, $indent, $value);
                $value = $context->find('str');
                $buffer .= $this->sectionAa413acb7776474efcb52c4cbf9fc6f9($context, $indent, $value);
                $buffer .= '</span>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section5a7f022ea4dc1bd204a4c48ff2b8dc1a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <span class="badge rounded-pill bg-secondary text-dark order-3">{{#str}}notavailable{{/str}}</span>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <span class="badge rounded-pill bg-secondary text-dark order-3">';
                $value = $context->find('str');
                $buffer .= $this->section31ae0fbdc439c6d3e86010ee0fe2597e($context, $indent, $value);
                $buffer .= '</span>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    public function blockEf09851a04fea3a91c898b8db7b03577($context)
    {
        $indent = $buffer = '';
        $buffer .= ' badge rounded-pill bg-secondary text-dark dropdown-toggle border-0';
    
        return $buffer;
    }
}
