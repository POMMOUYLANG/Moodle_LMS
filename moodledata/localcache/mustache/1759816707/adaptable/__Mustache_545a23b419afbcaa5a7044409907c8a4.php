<?php

class __Mustache_545a23b419afbcaa5a7044409907c8a4 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('secondarynavigation');
        if (empty($value)) {
            
            $buffer .= $indent . '    <ul class="nav nav-tabs" role="tablist">
';
            $buffer .= $indent . '        <!-- First the top most node and immediate children -->
';
            $buffer .= $indent . '        <li class="nav-item">
';
            $buffer .= $indent . '            <a class="nav-link active" href="#link';
            $value = $this->resolveValue($context->findDot('node.key'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '" data-bs-toggle="tab" role="tab" aria-selected="true">';
            $value = $this->resolveValue($context->findDot('node.text'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '</a>
';
            $buffer .= $indent . '        </li>
';
            $buffer .= $indent . '        <!-- Now the first level children with sub nodes -->
';
            $value = $context->findDot('node.children');
            $buffer .= $this->section4df6fd3d2ed9f8f8852f24e9492fe887($context, $indent, $value);
            $buffer .= $indent . '    </ul>
';
        }
        $buffer .= $indent . '<div class="tab-content mt-3">
';
        $buffer .= $indent . '    <div class="tab-pane active" id="link';
        $value = $this->resolveValue($context->findDot('node.key'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '" ';
        $value = $context->find('secondarynavigation');
        if (empty($value)) {
            
            $buffer .= 'role="tabpanel"';
        }
        $buffer .= '>
';
        $buffer .= $indent . '        <div class="container-fluid">
';
        $buffer .= $indent . '            <div class="row">
';
        $buffer .= $indent . '                <div class="col-sm-3">
';
        $buffer .= $indent . '                </div>
';
        $buffer .= $indent . '                <div class="col">
';
        $buffer .= $indent . '                    <ul class="list-unstyled">
';
        $value = $context->findDot('node.children');
        $buffer .= $this->section108f2967cf9915e0fb4d208c372e244a($context, $indent, $value);
        $buffer .= $indent . '                    </ul>
';
        $buffer .= $indent . '                </div>
';
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '
';
        $value = $context->findDot('node.children');
        $buffer .= $this->section66147866855bf66ee0c97e75a9569c33($context, $indent, $value);
        $buffer .= $indent . '        </div>
';
        $buffer .= $indent . '    </div>
';
        $value = $context->findDot('node.children');
        $buffer .= $this->section1479876602ecc5915d5dcd87004a346c($context, $indent, $value);
        $buffer .= $indent . '</div>
';

        return $buffer;
    }

    private function section06692563ee9579e41c158a25e7b8fab6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    {{^is_short_branch}}
                        <li class="nav-item">
                            <a class="nav-link" href="#link{{key}}" data-bs-toggle="tab" role="tab" aria-selected="false" tabindex="-1">{{text}}</a>
                        </li>
                    {{/is_short_branch}}
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('is_short_branch');
                if (empty($value)) {
                    
                    $buffer .= $indent . '                        <li class="nav-item">
';
                    $buffer .= $indent . '                            <a class="nav-link" href="#link';
                    $value = $this->resolveValue($context->find('key'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '" data-bs-toggle="tab" role="tab" aria-selected="false" tabindex="-1">';
                    $value = $this->resolveValue($context->find('text'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '</a>
';
                    $buffer .= $indent . '                        </li>
';
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section96913946d2c0e9b03d4570b965e5ff71(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                {{#display}}
                    {{^is_short_branch}}
                        <li class="nav-item">
                            <a class="nav-link" href="#link{{key}}" data-bs-toggle="tab" role="tab" aria-selected="false" tabindex="-1">{{text}}</a>
                        </li>
                    {{/is_short_branch}}
                {{/display}}
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('display');
                $buffer .= $this->section06692563ee9579e41c158a25e7b8fab6($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4df6fd3d2ed9f8f8852f24e9492fe887(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            {{#children.count}}
                {{#display}}
                    {{^is_short_branch}}
                        <li class="nav-item">
                            <a class="nav-link" href="#link{{key}}" data-bs-toggle="tab" role="tab" aria-selected="false" tabindex="-1">{{text}}</a>
                        </li>
                    {{/is_short_branch}}
                {{/display}}
            {{/children.count}}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->findDot('children.count');
                $buffer .= $this->section96913946d2c0e9b03d4570b965e5ff71($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section90ba6c44e84ae58e6a64815733addd8c(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                    <li><a href="{{{action}}}">{{text}}</a></li>
                                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                                    <li><a href="';
                $value = $this->resolveValue($context->find('action'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '">';
                $value = $this->resolveValue($context->find('text'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</a></li>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section108f2967cf9915e0fb4d208c372e244a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                            {{^children.count}}
                                {{#display}}
                                    <li><a href="{{{action}}}">{{text}}</a></li>
                                {{/display}}
                            {{/children.count}}
                        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->findDot('children.count');
                if (empty($value)) {
                    
                    $value = $context->find('display');
                    $buffer .= $this->section90ba6c44e84ae58e6a64815733addd8c($context, $indent, $value);
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4a94d8da24d2b62547a5dc6f95f3758e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '<h4><a href="{{action}}">{{text}}</a></h4>';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= '<h4><a href="';
                $value = $this->resolveValue($context->find('action'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">';
                $value = $this->resolveValue($context->find('text'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</a></h4>';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2ec771be5d3a4dfd86054f31a299f11e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                            {{> core/settings_link_page_single }}
                                        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core/settings_link_page_single')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                                            ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2fad4859f274248465b8e7149e336a9a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                            <hr>
                            <div class="row">
                                <div class="col-sm-3">
                                    {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                                    {{^action}}<h4>{{text}}</h4>{{/action}}
                                </div>
                                <div class="col">
                                    <ul class="list-unstyled">
                                        {{#children}}
                                            {{> core/settings_link_page_single }}
                                        {{/children}}
                                    </ul>
                                </div>
                            </div>
                        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                            <hr>
';
                $buffer .= $indent . '                            <div class="row">
';
                $buffer .= $indent . '                                <div class="col-sm-3">
';
                $buffer .= $indent . '                                    ';
                $value = $context->find('action');
                $buffer .= $this->section4a94d8da24d2b62547a5dc6f95f3758e($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                                    ';
                $value = $context->find('action');
                if (empty($value)) {
                    
                    $buffer .= '<h4>';
                    $value = $this->resolveValue($context->find('text'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '</h4>';
                }
                $buffer .= '
';
                $buffer .= $indent . '                                </div>
';
                $buffer .= $indent . '                                <div class="col">
';
                $buffer .= $indent . '                                    <ul class="list-unstyled">
';
                $value = $context->find('children');
                $buffer .= $this->section2ec771be5d3a4dfd86054f31a299f11e($context, $indent, $value);
                $buffer .= $indent . '                                    </ul>
';
                $buffer .= $indent . '                                </div>
';
                $buffer .= $indent . '                            </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section9e67abf862776a4a60d36553cfdd6ed4(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                        {{#is_short_branch}}
                            <hr>
                            <div class="row">
                                <div class="col-sm-3">
                                    {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                                    {{^action}}<h4>{{text}}</h4>{{/action}}
                                </div>
                                <div class="col">
                                    <ul class="list-unstyled">
                                        {{#children}}
                                            {{> core/settings_link_page_single }}
                                        {{/children}}
                                    </ul>
                                </div>
                            </div>
                        {{/is_short_branch}}
                    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('is_short_branch');
                $buffer .= $this->section2fad4859f274248465b8e7149e336a9a($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section6734b50f3837d1129c41c5f654ff0689(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    {{#children.count}}
                        {{#is_short_branch}}
                            <hr>
                            <div class="row">
                                <div class="col-sm-3">
                                    {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                                    {{^action}}<h4>{{text}}</h4>{{/action}}
                                </div>
                                <div class="col">
                                    <ul class="list-unstyled">
                                        {{#children}}
                                            {{> core/settings_link_page_single }}
                                        {{/children}}
                                    </ul>
                                </div>
                            </div>
                        {{/is_short_branch}}
                    {{/children.count}}
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->findDot('children.count');
                $buffer .= $this->section9e67abf862776a4a60d36553cfdd6ed4($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section66147866855bf66ee0c97e75a9569c33(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                {{#display}}
                    {{#children.count}}
                        {{#is_short_branch}}
                            <hr>
                            <div class="row">
                                <div class="col-sm-3">
                                    {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                                    {{^action}}<h4>{{text}}</h4>{{/action}}
                                </div>
                                <div class="col">
                                    <ul class="list-unstyled">
                                        {{#children}}
                                            {{> core/settings_link_page_single }}
                                        {{/children}}
                                    </ul>
                                </div>
                            </div>
                        {{/is_short_branch}}
                    {{/children.count}}
                {{/display}}
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('display');
                $buffer .= $this->section6734b50f3837d1129c41c5f654ff0689($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4845f37ccefb945467c4c4e7ee2bfee8(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                        {{^children.count}}
                                            <li><a href="{{{action}}}">{{text}}</a></li>
                                        {{/children.count}}
                                    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->findDot('children.count');
                if (empty($value)) {
                    
                    $buffer .= $indent . '                                            <li><a href="';
                    $value = $this->resolveValue($context->find('action'), $context);
                    $buffer .= ($value === null ? '' : $value);
                    $buffer .= '">';
                    $value = $this->resolveValue($context->find('text'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '</a></li>
';
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section9580eb1ee60b8305a93feffc6b3c38ad(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                    {{#display}}
                                        {{^children.count}}
                                            <li><a href="{{{action}}}">{{text}}</a></li>
                                        {{/children.count}}
                                    {{/display}}
                                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('display');
                $buffer .= $this->section4845f37ccefb945467c4c4e7ee2bfee8($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section7c56722c3b6d33a1fa1514a30a2f7eec(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                                {{> core/settings_link_page_single }}
                                            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core/settings_link_page_single')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                                                ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionEa90b71cfb07394f901bc85af2cff362(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                <hr>
                                <div class="row">
                                    <div class="col-sm-3">
                                        {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                                        {{^action}}<h4>{{text}}</h4>{{/action}}
                                    </div>
                                    <div class="col-sm-9">
                                        <ul class="list-unstyled">
                                            {{#children}}
                                                {{> core/settings_link_page_single }}
                                            {{/children}}
                                        </ul>
                                    </div>
                                </div>
                            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                                <hr>
';
                $buffer .= $indent . '                                <div class="row">
';
                $buffer .= $indent . '                                    <div class="col-sm-3">
';
                $buffer .= $indent . '                                        ';
                $value = $context->find('action');
                $buffer .= $this->section4a94d8da24d2b62547a5dc6f95f3758e($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                                        ';
                $value = $context->find('action');
                if (empty($value)) {
                    
                    $buffer .= '<h4>';
                    $value = $this->resolveValue($context->find('text'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '</h4>';
                }
                $buffer .= '
';
                $buffer .= $indent . '                                    </div>
';
                $buffer .= $indent . '                                    <div class="col-sm-9">
';
                $buffer .= $indent . '                                        <ul class="list-unstyled">
';
                $value = $context->find('children');
                $buffer .= $this->section7c56722c3b6d33a1fa1514a30a2f7eec($context, $indent, $value);
                $buffer .= $indent . '                                        </ul>
';
                $buffer .= $indent . '                                    </div>
';
                $buffer .= $indent . '                                </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section97ac82773cfdd4d4ad0b47d79d48f4f6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                            {{#children.count}}
                                <hr>
                                <div class="row">
                                    <div class="col-sm-3">
                                        {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                                        {{^action}}<h4>{{text}}</h4>{{/action}}
                                    </div>
                                    <div class="col-sm-9">
                                        <ul class="list-unstyled">
                                            {{#children}}
                                                {{> core/settings_link_page_single }}
                                            {{/children}}
                                        </ul>
                                    </div>
                                </div>
                            {{/children.count}}
                        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->findDot('children.count');
                $buffer .= $this->sectionEa90b71cfb07394f901bc85af2cff362($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section9747f4c2785e4979baf0562eb073860b(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                        {{#display}}
                            {{#children.count}}
                                <hr>
                                <div class="row">
                                    <div class="col-sm-3">
                                        {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                                        {{^action}}<h4>{{text}}</h4>{{/action}}
                                    </div>
                                    <div class="col-sm-9">
                                        <ul class="list-unstyled">
                                            {{#children}}
                                                {{> core/settings_link_page_single }}
                                            {{/children}}
                                        </ul>
                                    </div>
                                </div>
                            {{/children.count}}
                        {{/display}}
                    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('display');
                $buffer .= $this->section97ac82773cfdd4d4ad0b47d79d48f4f6($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section9bacce8d40aff105434e99f0c82522e1(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <div class="tab-pane" id="link{{key}}" {{^secondarynavigation}}role="tabpanel"{{/secondarynavigation}}>
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-3">
                            {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                            {{^action}}<h4>{{text}}</h4>{{/action}}
                        </div>
                        <div class="col-sm-9">
                            <ul class="list-unstyled">
                                {{#children}}
                                    {{#display}}
                                        {{^children.count}}
                                            <li><a href="{{{action}}}">{{text}}</a></li>
                                        {{/children.count}}
                                    {{/display}}
                                {{/children}}
                            </ul>
                        </div>
                    </div>
                    {{#children}}
                        {{#display}}
                            {{#children.count}}
                                <hr>
                                <div class="row">
                                    <div class="col-sm-3">
                                        {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                                        {{^action}}<h4>{{text}}</h4>{{/action}}
                                    </div>
                                    <div class="col-sm-9">
                                        <ul class="list-unstyled">
                                            {{#children}}
                                                {{> core/settings_link_page_single }}
                                            {{/children}}
                                        </ul>
                                    </div>
                                </div>
                            {{/children.count}}
                        {{/display}}
                    {{/children}}
                </div>
            </div>
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            <div class="tab-pane" id="link';
                $value = $this->resolveValue($context->find('key'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" ';
                $value = $context->find('secondarynavigation');
                if (empty($value)) {
                    
                    $buffer .= 'role="tabpanel"';
                }
                $buffer .= '>
';
                $buffer .= $indent . '                <div class="container-fluid">
';
                $buffer .= $indent . '                    <div class="row">
';
                $buffer .= $indent . '                        <div class="col-sm-3">
';
                $buffer .= $indent . '                            ';
                $value = $context->find('action');
                $buffer .= $this->section4a94d8da24d2b62547a5dc6f95f3758e($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                            ';
                $value = $context->find('action');
                if (empty($value)) {
                    
                    $buffer .= '<h4>';
                    $value = $this->resolveValue($context->find('text'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '</h4>';
                }
                $buffer .= '
';
                $buffer .= $indent . '                        </div>
';
                $buffer .= $indent . '                        <div class="col-sm-9">
';
                $buffer .= $indent . '                            <ul class="list-unstyled">
';
                $value = $context->find('children');
                $buffer .= $this->section9580eb1ee60b8305a93feffc6b3c38ad($context, $indent, $value);
                $buffer .= $indent . '                            </ul>
';
                $buffer .= $indent . '                        </div>
';
                $buffer .= $indent . '                    </div>
';
                $value = $context->find('children');
                $buffer .= $this->section9747f4c2785e4979baf0562eb073860b($context, $indent, $value);
                $buffer .= $indent . '                </div>
';
                $buffer .= $indent . '            </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1479876602ecc5915d5dcd87004a346c(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        {{#children.count}}
            <div class="tab-pane" id="link{{key}}" {{^secondarynavigation}}role="tabpanel"{{/secondarynavigation}}>
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-3">
                            {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                            {{^action}}<h4>{{text}}</h4>{{/action}}
                        </div>
                        <div class="col-sm-9">
                            <ul class="list-unstyled">
                                {{#children}}
                                    {{#display}}
                                        {{^children.count}}
                                            <li><a href="{{{action}}}">{{text}}</a></li>
                                        {{/children.count}}
                                    {{/display}}
                                {{/children}}
                            </ul>
                        </div>
                    </div>
                    {{#children}}
                        {{#display}}
                            {{#children.count}}
                                <hr>
                                <div class="row">
                                    <div class="col-sm-3">
                                        {{#action}}<h4><a href="{{action}}">{{text}}</a></h4>{{/action}}
                                        {{^action}}<h4>{{text}}</h4>{{/action}}
                                    </div>
                                    <div class="col-sm-9">
                                        <ul class="list-unstyled">
                                            {{#children}}
                                                {{> core/settings_link_page_single }}
                                            {{/children}}
                                        </ul>
                                    </div>
                                </div>
                            {{/children.count}}
                        {{/display}}
                    {{/children}}
                </div>
            </div>
        {{/children.count}}
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->findDot('children.count');
                $buffer .= $this->section9bacce8d40aff105434e99f0c82522e1($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
