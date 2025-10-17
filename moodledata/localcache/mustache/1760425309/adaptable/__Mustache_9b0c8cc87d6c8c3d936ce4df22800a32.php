<?php

class __Mustache_9b0c8cc87d6c8c3d936ce4df22800a32 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div class="card theme-card" role="listitem" id="theme-card-';
        $value = $this->resolveValue($context->find('choose'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '" aria-labelledby="theme-name-';
        $value = $this->resolveValue($context->find('choose'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= ' ';
        $value = $context->find('current');
        $buffer .= $this->section9563da32dc6ea8ebb1e6c291d195a674($context, $indent, $value);
        $buffer .= '">
';
        $buffer .= $indent . '    <div class="card-img card-img-top" style=\'background-image: url("';
        $value = $this->resolveValue($context->find('image'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '");\'></div>
';
        $buffer .= $indent . '    <div class="card-body p-3">
';
        $buffer .= $indent . '        <div class="d-flex">
';
        $buffer .= $indent . '            <div class="flex-grow-1">
';
        $buffer .= $indent . '                <h3 class="h5" id="theme-name-';
        $value = $this->resolveValue($context->find('choose'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">';
        $value = $this->resolveValue($context->find('name'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '</h3>
';
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '            <div>
';
        $buffer .= $indent . '                <button
';
        $buffer .= $indent . '                    type="button"
';
        $buffer .= $indent . '                    id="theme-preview-';
        $value = $this->resolveValue($context->find('choose'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                    class="btn btn-link p-0"
';
        $buffer .= $indent . '                    title="';
        $value = $context->find('str');
        $buffer .= $this->sectionC05e34c9062f481fde09d15354b3fc3c($context, $indent, $value);
        $buffer .= '"
';
        $buffer .= $indent . '                    data-action="preview"
';
        $buffer .= $indent . '                    data-name="';
        $value = $this->resolveValue($context->find('name'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                    data-image="';
        $value = $this->resolveValue($context->find('image'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                    data-current="';
        $value = $this->resolveValue($context->find('current'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                    data-actionurl="';
        $value = $this->resolveValue($context->find('actionurl'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                    data-choose="';
        $value = $this->resolveValue($context->find('choose'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                    data-sesskey="';
        $value = $this->resolveValue($context->find('sesskey'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '                    <i class="icon fa fa-info-circle m-0" aria-hidden="true"></i>
';
        $buffer .= $indent . '                    <span class="visually-hidden">';
        $value = $context->find('str');
        $buffer .= $this->sectionC05e34c9062f481fde09d15354b3fc3c($context, $indent, $value);
        $buffer .= '</span>
';
        $buffer .= $indent . '                </button>
';
        $value = $context->find('reporturl');
        $buffer .= $this->sectionBcb8ddbdbe8dd7262db98a22ab4e19f0($context, $indent, $value);
        $value = $context->find('settingsurl');
        $buffer .= $this->section7db4ede57e7d20fbfe79ccd53a2e7c43($context, $indent, $value);
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '        </div>
';
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '    <div class="d-flex align-items-end flex-column p-3">
';
        $value = $context->find('current');
        $buffer .= $this->section2fd5d15005f65506f4f43d43d7e9f066($context, $indent, $value);
        $value = $context->find('current');
        if (empty($value)) {
            
            $value = $context->find('actionurl');
            $buffer .= $this->section5194b1e4c6e1a4d4f3dd609705d147d9($context, $indent, $value);
        }
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '</div>
';
        $buffer .= $indent . '
';

        return $buffer;
    }

    private function section9563da32dc6ea8ebb1e6c291d195a674(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'current-theme-{{choose}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'current-theme-';
                $value = $this->resolveValue($context->find('choose'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC05e34c9062f481fde09d15354b3fc3c(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'previewthemename, moodle, {{name}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'previewthemename, moodle, ';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section44c3c42934c1d6467c25aab53bdcaa36(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'themeusagereportname, admin, {{name}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'themeusagereportname, admin, ';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionBcb8ddbdbe8dd7262db98a22ab4e19f0(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    <a
                        href="{{reporturl}}"
                        id="theme-usage-report-{{choose}}"
                        class="btn btn-link p-0 ms-2"
                        title="{{#str}}themeusagereportname, admin, {{name}}{{/str}}">
                        <i class="icon fa fa-area-chart m-0" aria-hidden="true"></i>
                        <span class="visually-hidden">{{#str}}themeusagereportname, admin, {{name}}{{/str}}</span>
                    </a>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                    <a
';
                $buffer .= $indent . '                        href="';
                $value = $this->resolveValue($context->find('reporturl'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"
';
                $buffer .= $indent . '                        id="theme-usage-report-';
                $value = $this->resolveValue($context->find('choose'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"
';
                $buffer .= $indent . '                        class="btn btn-link p-0 ms-2"
';
                $buffer .= $indent . '                        title="';
                $value = $context->find('str');
                $buffer .= $this->section44c3c42934c1d6467c25aab53bdcaa36($context, $indent, $value);
                $buffer .= '">
';
                $buffer .= $indent . '                        <i class="icon fa fa-area-chart m-0" aria-hidden="true"></i>
';
                $buffer .= $indent . '                        <span class="visually-hidden">';
                $value = $context->find('str');
                $buffer .= $this->section44c3c42934c1d6467c25aab53bdcaa36($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '                    </a>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionA72ce5bd96a447bad9614e23d4209764(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'themeeditsettingsname, admin, {{name}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'themeeditsettingsname, admin, ';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section7db4ede57e7d20fbfe79ccd53a2e7c43(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    <a
                        href="{{settingsurl}}"
                        id="theme-settings-{{choose}}"
                        class="btn btn-link p-0 ms-2"
                        title="{{#str}}themeeditsettingsname, admin, {{name}}{{/str}}">
                        <i class="icon fa fa-cog m-0" aria-hidden="true"></i>
                        <span class="visually-hidden">{{#str}}themeeditsettingsname, admin, {{name}}{{/str}}</span>
                    </a>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                    <a
';
                $buffer .= $indent . '                        href="';
                $value = $this->resolveValue($context->find('settingsurl'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"
';
                $buffer .= $indent . '                        id="theme-settings-';
                $value = $this->resolveValue($context->find('choose'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"
';
                $buffer .= $indent . '                        class="btn btn-link p-0 ms-2"
';
                $buffer .= $indent . '                        title="';
                $value = $context->find('str');
                $buffer .= $this->sectionA72ce5bd96a447bad9614e23d4209764($context, $indent, $value);
                $buffer .= '">
';
                $buffer .= $indent . '                        <i class="icon fa fa-cog m-0" aria-hidden="true"></i>
';
                $buffer .= $indent . '                        <span class="visually-hidden">';
                $value = $context->find('str');
                $buffer .= $this->sectionA72ce5bd96a447bad9614e23d4209764($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '                    </a>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionF75f2e5abd87a0454465a28226f7b099(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'currenttheme, moodle';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'currenttheme, moodle';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section67f026933a7af10c6060da64eb563822(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'configoverride, admin';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'configoverride, admin';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section53b69e18c458a5faffb338ea89b9fa57(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <div class="alert alert-info p-1">{{#str}}configoverride, admin{{/str}}</div>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <div class="alert alert-info p-1">';
                $value = $context->find('str');
                $buffer .= $this->section67f026933a7af10c6060da64eb563822($context, $indent, $value);
                $buffer .= '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2fd5d15005f65506f4f43d43d7e9f066(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <strong><span class="text-success" id="current-theme-{{choose}}">{{#str}}currenttheme, moodle{{/str}}</span></strong>
            {{#definedinconfig}}
                <div class="alert alert-info p-1">{{#str}}configoverride, admin{{/str}}</div>
            {{/definedinconfig}}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            <strong><span class="text-success" id="current-theme-';
                $value = $this->resolveValue($context->find('choose'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">';
                $value = $context->find('str');
                $buffer .= $this->sectionF75f2e5abd87a0454465a28226f7b099($context, $indent, $value);
                $buffer .= '</span></strong>
';
                $value = $context->find('definedinconfig');
                $buffer .= $this->section53b69e18c458a5faffb338ea89b9fa57($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section293484ed5cf156d84022066e8f26a26d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'selecttheme, moodle';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'selecttheme, moodle';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section7981f36b382ed5ff5367886b9b52741d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'selectthemename, moodle, {{name}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'selectthemename, moodle, ';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section5194b1e4c6e1a4d4f3dd609705d147d9(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <form method="post" action="{{actionurl}}" id="theme-select-form-{{choose}}">
                    <input type="hidden" name="sesskey" value="{{sesskey}}">
                    <input type="hidden" name="choose" value="{{choose}}">
                    <button type="submit" class="btn btn-primary">
                        <span aria-hidden="true">{{#str}}selecttheme, moodle{{/str}}</span>
                        <span class="visually-hidden">{{#str}}selectthemename, moodle, {{name}}{{/str}}</span>
                    </button>
                </form>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <form method="post" action="';
                $value = $this->resolveValue($context->find('actionurl'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" id="theme-select-form-';
                $value = $this->resolveValue($context->find('choose'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $buffer .= $indent . '                    <input type="hidden" name="sesskey" value="';
                $value = $this->resolveValue($context->find('sesskey'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $buffer .= $indent . '                    <input type="hidden" name="choose" value="';
                $value = $this->resolveValue($context->find('choose'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $buffer .= $indent . '                    <button type="submit" class="btn btn-primary">
';
                $buffer .= $indent . '                        <span aria-hidden="true">';
                $value = $context->find('str');
                $buffer .= $this->section293484ed5cf156d84022066e8f26a26d($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '                        <span class="visually-hidden">';
                $value = $context->find('str');
                $buffer .= $this->section7981f36b382ed5ff5367886b9b52741d($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '                    </button>
';
                $buffer .= $indent . '                </form>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
