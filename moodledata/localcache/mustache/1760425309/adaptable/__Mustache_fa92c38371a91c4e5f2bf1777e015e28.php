<?php

class __Mustache_fa92c38371a91c4e5f2bf1777e015e28 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '
';
        $buffer .= $indent . '<button class="btn btn-outline-secondary ms-2" type="button" id="dropdownFiltersButton" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-haspopup="true" aria-expanded="false" title="';
        $value = $context->find('str');
        $buffer .= $this->sectionF2207032852bc806cdc43df3b3b11324($context, $indent, $value);
        $buffer .= '">
';
        $buffer .= $indent . '    ';
        $value = $context->find('pix');
        $buffer .= $this->sectionE2ac6e2319122113f04a7a5e0841257d($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '    <span data-region="filter-button-label">
';
        $buffer .= $indent . '        ';
        $value = $context->find('filtersapplied');
        $buffer .= $this->section43ec75dbf834c55551894ca0e30f2c71($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '        ';
        $value = $context->find('filtersapplied');
        if (empty($value)) {
            
            $buffer .= ' ';
            $value = $context->find('str');
            $buffer .= $this->sectionF2207032852bc806cdc43df3b3b11324($context, $indent, $value);
            $buffer .= ' ';
        }
        $buffer .= '
';
        $buffer .= $indent . '    </span>
';
        $buffer .= $indent . '</button>
';
        $buffer .= $indent . '<div class="filters-dropdown dropdown-menu dropdown-menu-end moodle-has-zindex" aria-labelledby="dropdownFiltersButton">
';
        $buffer .= $indent . '    <div id="filters" class="reportbuilder-filters-sidebar scroll-shadow" data-region="report-filters">
';
        $buffer .= $indent . '        <div class="px-3 pt-3">
';
        $value = $context->find('filtersform');
        $buffer .= $this->section8fec6d905b258a7c95a67a64716534e3($context, $indent, $value);
        $buffer .= $indent . '        </div>
';
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '</div>
';
        $buffer .= $indent . '
';
        $value = $context->find('js');
        $buffer .= $this->section990e9bd102f6ffa2de7170707469a23f($context, $indent, $value);

        return $buffer;
    }

    private function sectionF2207032852bc806cdc43df3b3b11324(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' filters, moodle ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' filters, moodle ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionE2ac6e2319122113f04a7a5e0841257d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' i/filter, core ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' i/filter, core ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4541cddf8328ee80893decc4dfaf9643(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' filtersappliedx, core_reportbuilder, {{filtersapplied}} ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' filtersappliedx, core_reportbuilder, ';
                $value = $this->resolveValue($context->find('filtersapplied'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ' ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section43ec75dbf834c55551894ca0e30f2c71(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' {{#str}} filtersappliedx, core_reportbuilder, {{filtersapplied}} {{/str}} ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' ';
                $value = $context->find('str');
                $buffer .= $this->section4541cddf8328ee80893decc4dfaf9643($context, $indent, $value);
                $buffer .= ' ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section8fec6d905b258a7c95a67a64716534e3(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <div class="reportbuilder-filters-wrapper" data-region="filters-form">
                    {{{filtersform}}}
                </div>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <div class="reportbuilder-filters-wrapper" data-region="filters-form">
';
                $buffer .= $indent . '                    ';
                $value = $this->resolveValue($context->find('filtersform'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '                </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section990e9bd102f6ffa2de7170707469a23f(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    require([\'core_reportbuilder/filters\'], function(filters) {
        filters.init(\'reportbuilder-report-{{uniqid}}\', {{contextid}});
    });
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    require([\'core_reportbuilder/filters\'], function(filters) {
';
                $buffer .= $indent . '        filters.init(\'reportbuilder-report-';
                $value = $this->resolveValue($context->find('uniqid'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '\', ';
                $value = $this->resolveValue($context->find('contextid'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ');
';
                $buffer .= $indent . '    });
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
