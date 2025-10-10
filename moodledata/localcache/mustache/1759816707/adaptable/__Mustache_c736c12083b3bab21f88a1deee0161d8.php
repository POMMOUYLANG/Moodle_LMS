<?php

class __Mustache_c736c12083b3bab21f88a1deee0161d8 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '
';
        $buffer .= $indent . '<div class="loginform">
';
        $value = $context->find('maintenance');
        $buffer .= $this->sectionEf717f60864e507396cb776f401fd613($context, $indent, $value);
        $value = $context->find('error');
        $buffer .= $this->section721b249f13a0a54a178bdb0df3cea624($context, $indent, $value);
        $value = $context->find('cansignup');
        $buffer .= $this->section02e46b5694afd00eaa4a4e1d334db65d($context, $indent, $value);
        $value = $context->find('hasoidc');
        $buffer .= $this->sectionA6cd2e5b0cfa45c1f9280e86590a7bd6($context, $indent, $value);
        $value = $context->find('hasoidc');
        if (empty($value)) {
            
            if ($partial = $this->mustache->loadPartial('theme_adaptable/corepartials/loginup')) {
                $buffer .= $partial->renderInternal($context, $indent . '        ');
            }
        }
        $value = $context->find('hasinstructions');
        $buffer .= $this->section1fd1e109e7812e5cb00b8bce6643df1e($context, $indent, $value);
        $value = $context->find('cansignup');
        $buffer .= $this->section0f8ea48f1d09139d01d4915494762b5c($context, $indent, $value);
        $value = $context->find('canloginasguest');
        $buffer .= $this->sectionDe6417ec2a7a3ec4483563a63cf7f1e1($context, $indent, $value);
        $value = $context->find('hasoidc');
        $buffer .= $this->section184293f795475e106ba76f709481d8ef($context, $indent, $value);
        $value = $context->find('hasoidc');
        if (empty($value)) {
            
            $value = $context->find('hasidentityproviders');
            $buffer .= $this->sectionCf95ede50714bd1de8d5980a36c41533($context, $indent, $value);
        }
        $buffer .= $indent . '    <div class="login-divider"></div>
';
        $buffer .= $indent . '    <div class="d-flex">
';
        $value = $context->find('languagemenu');
        $buffer .= $this->sectionF14d80a3e07dfc2666df87d17d3d8cc8($context, $indent, $value);
        $buffer .= $indent . '        <button type="button" class="btn btn-secondary" ';
        $buffer .= ' data-modal="alert"';
        $buffer .= ' data-modal-title-str=\'["cookiesenabled", "core"]\' ';
        $buffer .= ' data-modal-content-str=\'["cookiesenabled_help_html", "core"]\'';
        $buffer .= '>';
        $value = $context->find('str');
        $buffer .= $this->sectionFcb729cc74d31bce5e3746aa60b79a2e($context, $indent, $value);
        $buffer .= '</button>
';
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '</div>
';
        $buffer .= $indent . '
';
        $value = $context->find('js');
        $buffer .= $this->section4a6eee035d80a0c1b39c0718cd5f551a($context, $indent, $value);

        return $buffer;
    }

    private function sectionEf717f60864e507396cb776f401fd613(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="alert alert-danger login-maintenance">
            {{{maintenance}}}
        </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="alert alert-danger login-maintenance">
';
                $buffer .= $indent . '            ';
                $value = $this->resolveValue($context->find('maintenance'), $context);
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

    private function section721b249f13a0a54a178bdb0df3cea624(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <a href="#" id="loginerrormessage" class="visually-hidden">{{error}}</a>
        <div class="alert alert-danger" role="alert">{{error}}</div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <a href="#" id="loginerrormessage" class="visually-hidden">';
                $value = $this->resolveValue($context->find('error'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</a>
';
                $buffer .= $indent . '        <div class="alert alert-danger" role="alert">';
                $value = $this->resolveValue($context->find('error'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section78c7558fe34a1190743ac70d6d336ab2(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' tocreatenewaccount ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' tocreatenewaccount ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section02e46b5694afd00eaa4a4e1d334db65d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <a href="{{signupurl}}" class="visually-hidden">{{#str}} tocreatenewaccount {{/str}}</a>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <a href="';
                $value = $this->resolveValue($context->find('signupurl'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" class="visually-hidden">';
                $value = $context->find('str');
                $buffer .= $this->section78c7558fe34a1190743ac70d6d336ab2($context, $indent, $value);
                $buffer .= '</a>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionE384f0e9b1fcc321a1a78dba1d43f63f(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' potentialidps, auth ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' potentialidps, auth ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section27e7e0ef90e5ca4258c38ec6519a63b2(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                            <img src="{{iconurl}}" alt="{{name}}" width="24" height="24"/>
                        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                            <img src="';
                $value = $this->resolveValue($context->find('iconurl'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" alt="';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" width="24" height="24"/>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionD9be8f73c231ebc009b0c6f077bff206(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <a href="{{url}}" title="{{name}}">
                    <button class="loginbtn btn login-identityprovider-btn btn-block">
                        {{#iconurl}}
                            <img src="{{iconurl}}" alt="{{name}}" width="24" height="24"/>
                        {{/iconurl}}
                        {{name}}
                    </button>
                </a>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <a href="';
                $value = $this->resolveValue($context->find('url'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" title="';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $buffer .= $indent . '                    <button class="loginbtn btn login-identityprovider-btn btn-block">
';
                $value = $context->find('iconurl');
                $buffer .= $this->section27e7e0ef90e5ca4258c38ec6519a63b2($context, $indent, $value);
                $buffer .= $indent . '                        ';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '
';
                $buffer .= $indent . '                    </button>
';
                $buffer .= $indent . '                </a>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionCf95ede50714bd1de8d5980a36c41533(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="login-divider"></div>
        <div class="login-identityproviders">
            <h2 class="login-heading">{{#str}} potentialidps, auth {{/str}}</h2>
            {{#identityproviders}}
                <a href="{{url}}" title="{{name}}">
                    <button class="loginbtn btn login-identityprovider-btn btn-block">
                        {{#iconurl}}
                            <img src="{{iconurl}}" alt="{{name}}" width="24" height="24"/>
                        {{/iconurl}}
                        {{name}}
                    </button>
                </a>
            {{/identityproviders}}
        </div>
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="login-divider"></div>
';
                $buffer .= $indent . '        <div class="login-identityproviders">
';
                $buffer .= $indent . '            <h2 class="login-heading">';
                $value = $context->find('str');
                $buffer .= $this->sectionE384f0e9b1fcc321a1a78dba1d43f63f($context, $indent, $value);
                $buffer .= '</h2>
';
                $value = $context->find('identityproviders');
                $buffer .= $this->sectionD9be8f73c231ebc009b0c6f077bff206($context, $indent, $value);
                $buffer .= $indent . '        </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionA6cd2e5b0cfa45c1f9280e86590a7bd6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        {{#hasidentityproviders}}
        <div class="login-divider"></div>
        <div class="login-identityproviders">
            <h2 class="login-heading">{{#str}} potentialidps, auth {{/str}}</h2>
            {{#identityproviders}}
                <a href="{{url}}" title="{{name}}">
                    <button class="loginbtn btn login-identityprovider-btn btn-block">
                        {{#iconurl}}
                            <img src="{{iconurl}}" alt="{{name}}" width="24" height="24"/>
                        {{/iconurl}}
                        {{name}}
                    </button>
                </a>
            {{/identityproviders}}
        </div>
        {{/hasidentityproviders}}
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('hasidentityproviders');
                $buffer .= $this->sectionCf95ede50714bd1de8d5980a36c41533($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionEf2e3defecc35d84a091ece31c5fc7e0(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'mb-3';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'mb-3';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionB681534bda1faeeb31506c30e72ff16e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'firsttime';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'firsttime';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1fd1e109e7812e5cb00b8bce6643df1e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="login-divider"></div>
        <div class="login-instructions {{#cansignup}}mb-3{{/cansignup}}">
            <h2 class="login-heading">{{#str}}firsttime{{/str}}</h2>
            {{{instructions}}}
        </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="login-divider"></div>
';
                $buffer .= $indent . '        <div class="login-instructions ';
                $value = $context->find('cansignup');
                $buffer .= $this->sectionEf2e3defecc35d84a091ece31c5fc7e0($context, $indent, $value);
                $buffer .= '">
';
                $buffer .= $indent . '            <h2 class="login-heading">';
                $value = $context->find('str');
                $buffer .= $this->sectionB681534bda1faeeb31506c30e72ff16e($context, $indent, $value);
                $buffer .= '</h2>
';
                $buffer .= $indent . '            ';
                $value = $this->resolveValue($context->find('instructions'), $context);
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

    private function section47f819a53e4575a4e7767be1939ab3bf(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'startsignup';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'startsignup';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0f8ea48f1d09139d01d4915494762b5c(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="login-signup">
            <a class="btn btn-secondary" href="{{signupurl}}">{{#str}}startsignup{{/str}}</a>
        </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="login-signup">
';
                $buffer .= $indent . '            <a class="btn btn-secondary" href="';
                $value = $this->resolveValue($context->find('signupurl'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">';
                $value = $context->find('str');
                $buffer .= $this->section47f819a53e4575a4e7767be1939ab3bf($context, $indent, $value);
                $buffer .= '</a>
';
                $buffer .= $indent . '        </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionDe6417ec2a7a3ec4483563a63cf7f1e1(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="login-divider"></div>
        {{> theme_adaptable/corepartials/loginguest }}
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="login-divider"></div>
';
                if ($partial = $this->mustache->loadPartial('theme_adaptable/corepartials/loginguest')) {
                    $buffer .= $partial->renderInternal($context, $indent . '        ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section37e57a56dc51b3d43bcbe7e51477f635(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' logintextmanualbutton, theme_adaptable ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' logintextmanualbutton, theme_adaptable ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section184293f795475e106ba76f709481d8ef(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <button class="loginbtn btn btn-secondary btn-block mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#loginmanual"
        aria-expanded="false" aria-controls="loginmanual">
        {{#str}} logintextmanualbutton, theme_adaptable {{/str}}
    </button>
    <div class="collapse" id="loginmanual">
        {{> theme_adaptable/corepartials/loginup }}
    </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <button class="loginbtn btn btn-secondary btn-block mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#loginmanual"
';
                $buffer .= $indent . '        aria-expanded="false" aria-controls="loginmanual">
';
                $buffer .= $indent . '        ';
                $value = $context->find('str');
                $buffer .= $this->section37e57a56dc51b3d43bcbe7e51477f635($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '    </button>
';
                $buffer .= $indent . '    <div class="collapse" id="loginmanual">
';
                if ($partial = $this->mustache->loadPartial('theme_adaptable/corepartials/loginup')) {
                    $buffer .= $partial->renderInternal($context, $indent . '        ');
                }
                $buffer .= $indent . '    </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionF14d80a3e07dfc2666df87d17d3d8cc8(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <div class="login-languagemenu">
                {{>core/action_menu}}
            </div>
            <div class="divider border-start align-self-center mx-3"></div>
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            <div class="login-languagemenu">
';
                if ($partial = $this->mustache->loadPartial('core/action_menu')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                ');
                }
                $buffer .= $indent . '            </div>
';
                $buffer .= $indent . '            <div class="divider border-start align-self-center mx-3"></div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionFcb729cc74d31bce5e3746aa60b79a2e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'cookiesnotice';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'cookiesnotice';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section691cc2f9954bba7bfc6c89b209875ecc(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            var userNameField = document.getElementById(\'username\');
            if (userNameField.value.length == 0) {
                userNameField.focus();
            } else {
                document.getElementById(\'password\').focus();
            }
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            var userNameField = document.getElementById(\'username\');
';
                $buffer .= $indent . '            if (userNameField.value.length == 0) {
';
                $buffer .= $indent . '                userNameField.focus();
';
                $buffer .= $indent . '            } else {
';
                $buffer .= $indent . '                document.getElementById(\'password\').focus();
';
                $buffer .= $indent . '            }
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionEb31e0a905855a4df03d13e2bbd239e8(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        document.getElementById(\'loginerrormessage\').focus();
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        document.getElementById(\'loginerrormessage\').focus();
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4a6eee035d80a0c1b39c0718cd5f551a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    {{^error}}
        {{#autofocusform}}
            var userNameField = document.getElementById(\'username\');
            if (userNameField.value.length == 0) {
                userNameField.focus();
            } else {
                document.getElementById(\'password\').focus();
            }
        {{/autofocusform}}
    {{/error}}
    {{#error}}
        document.getElementById(\'loginerrormessage\').focus();
    {{/error}}
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('error');
                if (empty($value)) {
                    
                    $value = $context->find('autofocusform');
                    $buffer .= $this->section691cc2f9954bba7bfc6c89b209875ecc($context, $indent, $value);
                }
                $value = $context->find('error');
                $buffer .= $this->sectionEb31e0a905855a4df03d13e2bbd239e8($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
