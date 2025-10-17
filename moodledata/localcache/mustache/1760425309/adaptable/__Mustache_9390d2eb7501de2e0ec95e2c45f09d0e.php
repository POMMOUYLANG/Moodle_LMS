<?php

class __Mustache_9390d2eb7501de2e0ec95e2c45f09d0e extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('maturity');
        $buffer .= $this->section549d02abffa20e0fdfb1e9b5a1110072($context, $indent, $value);
        $value = $context->find('maturity');
        if (empty($value)) {
            
            $buffer .= $indent . '<h3>';
            $value = $this->resolveValue($context->find('versioninfo'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $value = $context->find('localversioninfo');
            $buffer .= $this->sectionC895adfacab99282a78460abf987d9c2($context, $indent, $value);
            $buffer .= '</h3>
';
            $value = $this->resolveValue($context->find('privacynote'), $context);
            $buffer .= $indent . ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '}
';
        }
        $value = $context->find('versioncheck');
        $buffer .= $this->sectionC4d6d069bb1b600445bd3377fc7f23ad($context, $indent, $value);
        $buffer .= $indent . '<ul class="nav nav-tabs" role="tablist">
';
        $value = $context->find('tabs');
        $buffer .= $this->section59667cb8f4dffce584bd730413d109e6($context, $indent, $value);
        $buffer .= $indent . '</ul>
';
        $buffer .= $indent . '<div class="tab-content mt-3">
';
        $value = $context->find('tabs');
        $buffer .= $this->sectionA88eecfc78fbcb0f91da84593024026e($context, $indent, $value);
        $buffer .= $indent . '</div>
';
        $value = $context->find('js');
        $buffer .= $this->section94d2583a554c698c6977c506a073a078($context, $indent, $value);

        return $buffer;
    }

    private function sectionC895adfacab99282a78460abf987d9c2(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' {{localversioninfo}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' ';
                $value = $this->resolveValue($context->find('localversioninfo'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section549d02abffa20e0fdfb1e9b5a1110072(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
<div class="alert alert-{{maturityalert}} mb-3">
<h3>{{versioninfo}}{{#localversioninfo}} {{localversioninfo}}{{/localversioninfo}}</h3>
<p>{{{maturity}}}</p>
{{{privacynote}}}
</div>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '<div class="alert alert-';
                $value = $this->resolveValue($context->find('maturityalert'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ' mb-3">
';
                $buffer .= $indent . '<h3>';
                $value = $this->resolveValue($context->find('versioninfo'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $value = $context->find('localversioninfo');
                $buffer .= $this->sectionC895adfacab99282a78460abf987d9c2($context, $indent, $value);
                $buffer .= '</h3>
';
                $buffer .= $indent . '<p>';
                $value = $this->resolveValue($context->find('maturity'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '</p>
';
                $value = $this->resolveValue($context->find('privacynote'), $context);
                $buffer .= $indent . ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC4d6d069bb1b600445bd3377fc7f23ad(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
<div class="alert alert-warning mb-3">
{{{versioncheck}}}
</div>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '<div class="alert alert-warning mb-3">
';
                $value = $this->resolveValue($context->find('versioncheck'), $context);
                $buffer .= $indent . ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section3ff98c1deac347d8c4a99550583d4940(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' active';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' active';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC5c3680fd683097eb35564dc25b12eb5(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' fst-italic';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' fst-italic';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionCe04cacc15f032e9e9f826b761c9b814(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'aria-selected="true"';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'aria-selected="true"';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section59667cb8f4dffce584bd730413d109e6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <li class="nav-item">
            <a href="#{{name}}" class="nav-link{{#active}} active{{/active}}{{#disabled}} fst-italic{{/disabled}}" data-bs-toggle="tab" role="tab"
                {{#active}}aria-selected="true"{{/active}}
                {{^active}}aria-selected="false" tabindex="-1"{{/active}}>{{displayname}}</a>
        </li>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <li class="nav-item">
';
                $buffer .= $indent . '            <a href="#';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" class="nav-link';
                $value = $context->find('active');
                $buffer .= $this->section3ff98c1deac347d8c4a99550583d4940($context, $indent, $value);
                $value = $context->find('disabled');
                $buffer .= $this->sectionC5c3680fd683097eb35564dc25b12eb5($context, $indent, $value);
                $buffer .= '" data-bs-toggle="tab" role="tab"
';
                $buffer .= $indent . '                ';
                $value = $context->find('active');
                $buffer .= $this->sectionCe04cacc15f032e9e9f826b761c9b814($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                ';
                $value = $context->find('active');
                if (empty($value)) {
                    
                    $buffer .= 'aria-selected="false" tabindex="-1"';
                }
                $buffer .= '>';
                $value = $this->resolveValue($context->find('displayname'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</a>
';
                $buffer .= $indent . '        </li>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section05828eb925ccd1e324128320ddd16fea(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' disabled';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' disabled';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionA88eecfc78fbcb0f91da84593024026e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="tab-pane{{#active}} active{{/active}}{{#disabled}} disabled{{/disabled}}" id="{{name}}" role="tabpanel">
            {{{html}}}
        </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="tab-pane';
                $value = $context->find('active');
                $buffer .= $this->section3ff98c1deac347d8c4a99550583d4940($context, $indent, $value);
                $value = $context->find('disabled');
                $buffer .= $this->section05828eb925ccd1e324128320ddd16fea($context, $indent, $value);
                $buffer .= '" id="';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" role="tabpanel">
';
                $buffer .= $indent . '            ';
                $value = $this->resolveValue($context->find('html'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '        </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section94d2583a554c698c6977c506a073a078(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    const disabledtabs = document.getElementsByClassName("tab-pane disabled");
    disabledtabs.forEach(function(tab) {
        const settings = tab.querySelectorAll("input, select, textarea");
        settings.forEach(function(setting) {
            setting.setAttribute("disabled", "disabled");
        });
    });
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    const disabledtabs = document.getElementsByClassName("tab-pane disabled");
';
                $buffer .= $indent . '    disabledtabs.forEach(function(tab) {
';
                $buffer .= $indent . '        const settings = tab.querySelectorAll("input, select, textarea");
';
                $buffer .= $indent . '        settings.forEach(function(setting) {
';
                $buffer .= $indent . '            setting.setAttribute("disabled", "disabled");
';
                $buffer .= $indent . '        });
';
                $buffer .= $indent . '    });
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
