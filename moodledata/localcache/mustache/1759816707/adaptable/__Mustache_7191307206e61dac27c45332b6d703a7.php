<?php

class __Mustache_7191307206e61dac27c45332b6d703a7 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '
';
        if ($parent = $this->mustache->loadPartial('theme_boost/drawer')) {
            $context->pushBlockContext(array(
                'id' => array($this, 'block51699045cffb4d0a8968332510ef362f'),
                'drawerclasses' => array($this, 'blockD38028d67780ee6f785cf8e213f0a779'),
                'drawercloseonresize' => array($this, 'blockE052079a625ca42b568ba24af19cc7eb'),
                'drawerheading' => array($this, 'block007e63fc345bbf88d0067cd686b71e86'),
                'drawercontent' => array($this, 'blockBac088abeabdf174df3c3a1a4797d727'),
                'drawerstate' => array($this, 'blockA1dd4f2e68d7796ac435ab08723145ef'),
            ));
            $buffer .= $parent->renderInternal($context, $indent);
            $context->popBlockContext();
        }

        return $buffer;
    }

    private function section224a34078da1bb2a5ddb06d3d16a6347(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <img src="{{output.get_compact_logo_url}}" class="logo py-1 h-100" alt="{{sitename}}">
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= '            <img src="';
                $value = $this->resolveValue($context->findDot('output.get_compact_logo_url'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" class="logo py-1 h-100" alt="';
                $value = $this->resolveValue($context->find('sitename'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section67c59c18302e3b6896a1ce2ba2852316(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' t/expanded, core ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' t/expanded, core ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0ac795c23146489fad8f951c23f9a92a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' collapse, core ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' collapse, core ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionFb21be3147b00254c7256c9c85519aaf(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' t/collapsed, core ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' t/collapsed, core ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section8b5765485c94c190bf567731edb08c3a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' expand, core ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' expand, core ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section42d3261580621db2d21272bc68886853(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                {{^divider}}
                                    <a href="{{{url}}}" class="ps-5 bg-light list-group-item list-group-item-action">{{{text}}}</a>
                                {{/divider}}
                            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('divider');
                if (empty($value)) {
                    
                    $buffer .= $indent . '                                    <a href="';
                    $value = $this->resolveValue($context->find('url'), $context);
                    $buffer .= ($value === null ? '' : $value);
                    $buffer .= '" class="ps-5 bg-light list-group-item list-group-item-action">';
                    $value = $this->resolveValue($context->find('text'), $context);
                    $buffer .= ($value === null ? '' : $value);
                    $buffer .= '</a>
';
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section704a97a7205c29d4a89a022093a0345d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                            <a id="drop-down-{{sort}}" href="#" class="list-group-item list-group-item-action icons-collapse-expand collapsed d-flex" data-bs-toggle="collapse" data-bs-target="#drop-down-menu-{{sort}}" aria-expanded="false" aria-controls="drop-down-menu-{{sort}}">
                                {{{text}}}
                                <span class="ms-auto expanded-icon icon-no-margin mx-2">
                                    {{#pix}} t/expanded, core {{/pix}}
                                    <span class="visually-hidden">
                                        {{#str}} collapse, core {{/str}}
                                    </span>
                                </span>
                                <span class="ms-auto collapsed-icon icon-no-margin mx-2">
                                    {{#pix}} t/collapsed, core {{/pix}}
                                    <span class="visually-hidden">
                                        {{#str}} expand, core {{/str}}
                                    </span>
                                </span>
                            </a>
                            <div class="collapse list-group-item p-0 border-0" role="menu" id="drop-down-menu-{{sort}}" aria-labelledby="drop-down-{{sort}}">
                            {{#children}}
                                {{^divider}}
                                    <a href="{{{url}}}" class="ps-5 bg-light list-group-item list-group-item-action">{{{text}}}</a>
                                {{/divider}}
                            {{/children}}
                            </div>
                            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                            <a id="drop-down-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" href="#" class="list-group-item list-group-item-action icons-collapse-expand collapsed d-flex" data-bs-toggle="collapse" data-bs-target="#drop-down-menu-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" aria-expanded="false" aria-controls="drop-down-menu-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $buffer .= $indent . '                                ';
                $value = $this->resolveValue($context->find('text'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '                                <span class="ms-auto expanded-icon icon-no-margin mx-2">
';
                $buffer .= $indent . '                                    ';
                $value = $context->find('pix');
                $buffer .= $this->section67c59c18302e3b6896a1ce2ba2852316($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                                    <span class="visually-hidden">
';
                $buffer .= $indent . '                                        ';
                $value = $context->find('str');
                $buffer .= $this->section0ac795c23146489fad8f951c23f9a92a($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                                    </span>
';
                $buffer .= $indent . '                                </span>
';
                $buffer .= $indent . '                                <span class="ms-auto collapsed-icon icon-no-margin mx-2">
';
                $buffer .= $indent . '                                    ';
                $value = $context->find('pix');
                $buffer .= $this->sectionFb21be3147b00254c7256c9c85519aaf($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                                    <span class="visually-hidden">
';
                $buffer .= $indent . '                                        ';
                $value = $context->find('str');
                $buffer .= $this->section8b5765485c94c190bf567731edb08c3a($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                                    </span>
';
                $buffer .= $indent . '                                </span>
';
                $buffer .= $indent . '                            </a>
';
                $buffer .= $indent . '                            <div class="collapse list-group-item p-0 border-0" role="menu" id="drop-down-menu-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" aria-labelledby="drop-down-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $value = $context->find('children');
                $buffer .= $this->section42d3261580621db2d21272bc68886853($context, $indent, $value);
                $buffer .= $indent . '                            </div>
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

    private function sectionE89f12d27ca9e1be7a95b3e925ef31c0(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' {{.}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' ';
                $value = $this->resolveValue($context->last(), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionFc0c0b051caebb6243b5c2bd6d728967(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'aria-current="true"';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'aria-current="true"';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2346931619049240562a76cec5d8c0e6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                        {{^divider}}
                            {{#haschildren}}
                            <a id="drop-down-{{sort}}" href="#" class="list-group-item list-group-item-action icons-collapse-expand collapsed d-flex" data-bs-toggle="collapse" data-bs-target="#drop-down-menu-{{sort}}" aria-expanded="false" aria-controls="drop-down-menu-{{sort}}">
                                {{{text}}}
                                <span class="ms-auto expanded-icon icon-no-margin mx-2">
                                    {{#pix}} t/expanded, core {{/pix}}
                                    <span class="visually-hidden">
                                        {{#str}} collapse, core {{/str}}
                                    </span>
                                </span>
                                <span class="ms-auto collapsed-icon icon-no-margin mx-2">
                                    {{#pix}} t/collapsed, core {{/pix}}
                                    <span class="visually-hidden">
                                        {{#str}} expand, core {{/str}}
                                    </span>
                                </span>
                            </a>
                            <div class="collapse list-group-item p-0 border-0" role="menu" id="drop-down-menu-{{sort}}" aria-labelledby="drop-down-{{sort}}">
                            {{#children}}
                                {{^divider}}
                                    <a href="{{{url}}}" class="ps-5 bg-light list-group-item list-group-item-action">{{{text}}}</a>
                                {{/divider}}
                            {{/children}}
                            </div>
                            {{/haschildren}}
                            {{^haschildren}}
                            <a href="{{{url}}}" class="list-group-item list-group-item-action{{#isactive}} active{{/isactive}}{{#classes}} {{.}}{{/classes}}" {{#isactive}}aria-current="true"{{/isactive}}>
                                {{{text}}}
                            </a>
                            {{/haschildren}}
                        {{/divider}}
                    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('divider');
                if (empty($value)) {
                    
                    $value = $context->find('haschildren');
                    $buffer .= $this->section704a97a7205c29d4a89a022093a0345d($context, $indent, $value);
                    $value = $context->find('haschildren');
                    if (empty($value)) {
                        
                        $buffer .= $indent . '                            <a href="';
                        $value = $this->resolveValue($context->find('url'), $context);
                        $buffer .= ($value === null ? '' : $value);
                        $buffer .= '" class="list-group-item list-group-item-action';
                        $value = $context->find('isactive');
                        $buffer .= $this->section3ff98c1deac347d8c4a99550583d4940($context, $indent, $value);
                        $value = $context->find('classes');
                        $buffer .= $this->sectionE89f12d27ca9e1be7a95b3e925ef31c0($context, $indent, $value);
                        $buffer .= '" ';
                        $value = $context->find('isactive');
                        $buffer .= $this->sectionFc0c0b051caebb6243b5c2bd6d728967($context, $indent, $value);
                        $buffer .= '>
';
                        $buffer .= $indent . '                                ';
                        $value = $this->resolveValue($context->find('text'), $context);
                        $buffer .= ($value === null ? '' : $value);
                        $buffer .= '
';
                        $buffer .= $indent . '                            </a>
';
                    }
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section75b01c586df8285d62aadff3d7bcb82c(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <a id="drop-down-{{sort}}" href="#" class="list-group-item list-group-item-action icons-collapse-expand collapsed d-flex" data-bs-toggle="collapse" data-bs-target="#drop-down-menu-{{sort}}" aria-expanded="false" aria-controls="drop-down-menu-{{sort}}">
                    {{{text}}}
                    <span class="ms-auto expanded-icon icon-no-margin mx-2">
                        {{#pix}} t/expanded, core {{/pix}}
                        <span class="visually-hidden">
                            {{#str}} collapse, core {{/str}}
                        </span>
                    </span>
                    <span class="ms-auto collapsed-icon icon-no-margin mx-2">
                        {{#pix}} t/collapsed, core {{/pix}}
                        <span class="visually-hidden">
                            {{#str}} expand, core {{/str}}
                        </span>
                    </span>
                </a>
                <div class="collapse list-group-item p-0 border-0" role="menu" id="drop-down-menu-{{sort}}" aria-labelledby="drop-down-{{sort}}">
                    {{#children}}
                        {{^divider}}
                            {{#haschildren}}
                            <a id="drop-down-{{sort}}" href="#" class="list-group-item list-group-item-action icons-collapse-expand collapsed d-flex" data-bs-toggle="collapse" data-bs-target="#drop-down-menu-{{sort}}" aria-expanded="false" aria-controls="drop-down-menu-{{sort}}">
                                {{{text}}}
                                <span class="ms-auto expanded-icon icon-no-margin mx-2">
                                    {{#pix}} t/expanded, core {{/pix}}
                                    <span class="visually-hidden">
                                        {{#str}} collapse, core {{/str}}
                                    </span>
                                </span>
                                <span class="ms-auto collapsed-icon icon-no-margin mx-2">
                                    {{#pix}} t/collapsed, core {{/pix}}
                                    <span class="visually-hidden">
                                        {{#str}} expand, core {{/str}}
                                    </span>
                                </span>
                            </a>
                            <div class="collapse list-group-item p-0 border-0" role="menu" id="drop-down-menu-{{sort}}" aria-labelledby="drop-down-{{sort}}">
                            {{#children}}
                                {{^divider}}
                                    <a href="{{{url}}}" class="ps-5 bg-light list-group-item list-group-item-action">{{{text}}}</a>
                                {{/divider}}
                            {{/children}}
                            </div>
                            {{/haschildren}}
                            {{^haschildren}}
                            <a href="{{{url}}}" class="list-group-item list-group-item-action{{#isactive}} active{{/isactive}}{{#classes}} {{.}}{{/classes}}" {{#isactive}}aria-current="true"{{/isactive}}>
                                {{{text}}}
                            </a>
                            {{/haschildren}}
                        {{/divider}}
                    {{/children}}
                </div>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <a id="drop-down-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" href="#" class="list-group-item list-group-item-action icons-collapse-expand collapsed d-flex" data-bs-toggle="collapse" data-bs-target="#drop-down-menu-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" aria-expanded="false" aria-controls="drop-down-menu-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $buffer .= $indent . '                    ';
                $value = $this->resolveValue($context->find('text'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '                    <span class="ms-auto expanded-icon icon-no-margin mx-2">
';
                $buffer .= $indent . '                        ';
                $value = $context->find('pix');
                $buffer .= $this->section67c59c18302e3b6896a1ce2ba2852316($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                        <span class="visually-hidden">
';
                $buffer .= $indent . '                            ';
                $value = $context->find('str');
                $buffer .= $this->section0ac795c23146489fad8f951c23f9a92a($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                        </span>
';
                $buffer .= $indent . '                    </span>
';
                $buffer .= $indent . '                    <span class="ms-auto collapsed-icon icon-no-margin mx-2">
';
                $buffer .= $indent . '                        ';
                $value = $context->find('pix');
                $buffer .= $this->sectionFb21be3147b00254c7256c9c85519aaf($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                        <span class="visually-hidden">
';
                $buffer .= $indent . '                            ';
                $value = $context->find('str');
                $buffer .= $this->section8b5765485c94c190bf567731edb08c3a($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                        </span>
';
                $buffer .= $indent . '                    </span>
';
                $buffer .= $indent . '                </a>
';
                $buffer .= $indent . '                <div class="collapse list-group-item p-0 border-0" role="menu" id="drop-down-menu-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" aria-labelledby="drop-down-';
                $value = $this->resolveValue($context->find('sort'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $value = $context->find('children');
                $buffer .= $this->section2346931619049240562a76cec5d8c0e6($context, $indent, $value);
                $buffer .= $indent . '                </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section55e6ce7706d5ae6ca29e1e40467f2ec5(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'target="{{target}}"';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'target="';
                $value = $this->resolveValue($context->find('target'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section95478844bf084cfb0f6bfec0d39555f3(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                {{#haschildren}}
                <a id="drop-down-{{sort}}" href="#" class="list-group-item list-group-item-action icons-collapse-expand collapsed d-flex" data-bs-toggle="collapse" data-bs-target="#drop-down-menu-{{sort}}" aria-expanded="false" aria-controls="drop-down-menu-{{sort}}">
                    {{{text}}}
                    <span class="ms-auto expanded-icon icon-no-margin mx-2">
                        {{#pix}} t/expanded, core {{/pix}}
                        <span class="visually-hidden">
                            {{#str}} collapse, core {{/str}}
                        </span>
                    </span>
                    <span class="ms-auto collapsed-icon icon-no-margin mx-2">
                        {{#pix}} t/collapsed, core {{/pix}}
                        <span class="visually-hidden">
                            {{#str}} expand, core {{/str}}
                        </span>
                    </span>
                </a>
                <div class="collapse list-group-item p-0 border-0" role="menu" id="drop-down-menu-{{sort}}" aria-labelledby="drop-down-{{sort}}">
                    {{#children}}
                        {{^divider}}
                            {{#haschildren}}
                            <a id="drop-down-{{sort}}" href="#" class="list-group-item list-group-item-action icons-collapse-expand collapsed d-flex" data-bs-toggle="collapse" data-bs-target="#drop-down-menu-{{sort}}" aria-expanded="false" aria-controls="drop-down-menu-{{sort}}">
                                {{{text}}}
                                <span class="ms-auto expanded-icon icon-no-margin mx-2">
                                    {{#pix}} t/expanded, core {{/pix}}
                                    <span class="visually-hidden">
                                        {{#str}} collapse, core {{/str}}
                                    </span>
                                </span>
                                <span class="ms-auto collapsed-icon icon-no-margin mx-2">
                                    {{#pix}} t/collapsed, core {{/pix}}
                                    <span class="visually-hidden">
                                        {{#str}} expand, core {{/str}}
                                    </span>
                                </span>
                            </a>
                            <div class="collapse list-group-item p-0 border-0" role="menu" id="drop-down-menu-{{sort}}" aria-labelledby="drop-down-{{sort}}">
                            {{#children}}
                                {{^divider}}
                                    <a href="{{{url}}}" class="ps-5 bg-light list-group-item list-group-item-action">{{{text}}}</a>
                                {{/divider}}
                            {{/children}}
                            </div>
                            {{/haschildren}}
                            {{^haschildren}}
                            <a href="{{{url}}}" class="list-group-item list-group-item-action{{#isactive}} active{{/isactive}}{{#classes}} {{.}}{{/classes}}" {{#isactive}}aria-current="true"{{/isactive}}>
                                {{{text}}}
                            </a>
                            {{/haschildren}}
                        {{/divider}}
                    {{/children}}
                </div>
                {{/haschildren}}
                {{^haschildren}}
                <a href="{{{url}}}" {{#target}}target="{{target}}"{{/target}}class="list-group-item list-group-item-action{{#isactive}} active{{/isactive}}{{#classes}} {{.}}{{/classes}}" {{#isactive}}aria-current="true"{{/isactive}}>
                    {{{text}}}
                </a>
                {{/haschildren}}
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('haschildren');
                $buffer .= $this->section75b01c586df8285d62aadff3d7bcb82c($context, $indent, $value);
                $value = $context->find('haschildren');
                if (empty($value)) {
                    
                    $buffer .= $indent . '                <a href="';
                    $value = $this->resolveValue($context->find('url'), $context);
                    $buffer .= ($value === null ? '' : $value);
                    $buffer .= '" ';
                    $value = $context->find('target');
                    $buffer .= $this->section55e6ce7706d5ae6ca29e1e40467f2ec5($context, $indent, $value);
                    $buffer .= 'class="list-group-item list-group-item-action';
                    $value = $context->find('isactive');
                    $buffer .= $this->section3ff98c1deac347d8c4a99550583d4940($context, $indent, $value);
                    $value = $context->find('classes');
                    $buffer .= $this->sectionE89f12d27ca9e1be7a95b3e925ef31c0($context, $indent, $value);
                    $buffer .= '" ';
                    $value = $context->find('isactive');
                    $buffer .= $this->sectionFc0c0b051caebb6243b5c2bd6d728967($context, $indent, $value);
                    $buffer .= '>
';
                    $buffer .= $indent . '                    ';
                    $value = $this->resolveValue($context->find('text'), $context);
                    $buffer .= ($value === null ? '' : $value);
                    $buffer .= '
';
                    $buffer .= $indent . '                </a>
';
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    public function block51699045cffb4d0a8968332510ef362f($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . 'theme_adaptable-drawers-primary';
    
        return $buffer;
    }

    public function blockD38028d67780ee6f785cf8e213f0a779($context)
    {
        $indent = $buffer = '';
        $buffer .= 'drawer drawer-left drawer-primary';
    
        return $buffer;
    }

    public function blockE052079a625ca42b568ba24af19cc7eb($context)
    {
        $indent = $buffer = '';
        $buffer .= '1';
    
        return $buffer;
    }

    public function block007e63fc345bbf88d0067cd686b71e86($context)
    {
        $indent = $buffer = '';
        $value = $context->findDot('output.should_display_navbar_logo');
        $buffer .= $this->section224a34078da1bb2a5ddb06d3d16a6347($context, $indent, $value);
        $value = $context->findDot('output.should_display_navbar_logo');
        if (empty($value)) {
            
            $buffer .= $indent . '            ';
            $value = $this->resolveValue($context->find('sitename'), $context);
            $buffer .= ($value === null ? '' : $value);
            $buffer .= '
';
        }
    
        return $buffer;
    }

    public function blockBac088abeabdf174df3c3a1a4797d727($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . '        <div class="list-group">
';
        $value = $context->find('mobileprimarynav');
        $buffer .= $this->section95478844bf084cfb0f6bfec0d39555f3($context, $indent, $value);
        $buffer .= $indent . '        </div>
';
    
        return $buffer;
    }

    public function blockA1dd4f2e68d7796ac435ab08723145ef($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . 'show-drawer-primary';
    
        return $buffer;
    }
}
