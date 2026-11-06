<?php

namespace App\Support\OpenApi;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use ReflectionException;

/**
 * Lecture statique d'une action de contrôleur pour le contrat OpenAPI (API-06) :
 * règles de validation, code de succès, forme de la réponse et codes d'erreur métier.
 *
 * Les méthodes privées appelées via $this-> sont suivies sur deux niveaux, ce qui couvre
 * les validations factorisées (création et modification partagent une même méthode de règles).
 */
class ControllerInspector
{
    /** @var array<string, array{class: Class_, methods: array<string, ClassMethod>}> */
    private array $files = [];

    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    /**
     * @return array{rules: array<string, list<string>>, success: int, shape: string, errors: list<string>}
     */
    public function inspect(string $class, string $method): array
    {
        $nodes = $this->methodNodes($class, $method, 2);
        if ($nodes === []) {
            return ['rules' => [], 'success' => 200, 'statuses' => [], 'shape' => 'object', 'errors' => []];
        }
        $rules = [];
        foreach ($nodes as $node) {
            foreach ($this->validationArrays($node) as $array) {
                foreach ($this->rulesFrom($array) as $key => $tokens) {
                    $rules[$key] = array_values(array_unique(array_merge($rules[$key] ?? [], $tokens)));
                }
            }
        }
        $main = $nodes[0];

        return [
            'rules' => $rules,
            'success' => $this->successStatus($main),
            'statuses' => $this->statuses($nodes),
            // Une action qui délègue sa réponse (return $this->show(...)) prend la forme de la méthode appelée.
            'shape' => collect($nodes)->map(fn (ClassMethod $node) => $this->shape($node))->first(fn (string $shape) => $shape !== 'object') ?? 'object',
            'errors' => $this->businessErrors($nodes),
        ];
    }

    /**
     * @return list<ClassMethod>
     */
    private function methodNodes(string $class, string $method, int $depth): array
    {
        $node = $this->find($class, $method);
        if ($node === null) {
            return [];
        }
        $nodes = [$node];
        if ($depth > 0) {
            foreach ($this->finder->findInstanceOf($node, Expr\MethodCall::class) as $call) {
                if ($call->var instanceof Expr\Variable && $call->var->name === 'this' && $call->name instanceof Node\Identifier) {
                    $callee = $call->name->toString();
                    if ($callee !== $method && ! in_array($callee, ['paginated', 'user', 'audit', 'access', 'can', 'authorizePermission', 'deny'], true)) {
                        array_push($nodes, ...$this->methodNodes($class, $callee, $depth - 1));
                    }
                }
            }
        }

        return $nodes;
    }

    private function find(string $class, string $method): ?ClassMethod
    {
        try {
            $reflection = new ReflectionClass($class);
            if (! $reflection->hasMethod($method)) {
                return null;
            }
            $declaring = $reflection->getMethod($method)->getDeclaringClass();
        } catch (ReflectionException) {
            return null;
        }
        $file = (string) $declaring->getFileName();
        if (! isset($this->files[$file])) {
            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $statements = $parser->parse((string) file_get_contents($file)) ?? [];
            /** @var Class_|null $classNode */
            $classNode = $this->finder->findFirstInstanceOf($statements, Class_::class);
            $methods = [];
            foreach ($classNode?->getMethods() ?? [] as $classMethod) {
                $methods[$classMethod->name->toString()] = $classMethod;
            }
            $this->files[$file] = ['class' => $classNode, 'methods' => $methods];
        }

        return $this->files[$file]['methods'][$method] ?? null;
    }

    /**
     * @return list<Expr\Array_>
     */
    private function validationArrays(ClassMethod $method): array
    {
        $arrays = [];
        foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall) as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
            $arguments = array_map(fn ($a) => $a instanceof Node\Arg ? $a->value : null, $call->args);
            if ($call instanceof Expr\MethodCall && $name === 'validate') {
                $candidates = $arguments;
            } elseif ($call instanceof Expr\StaticCall && $name === 'make' && $call->class instanceof Node\Name && $call->class->getLast() === 'Validator') {
                $candidates = array_slice($arguments, 1, 1);
            } else {
                continue;
            }
            foreach ($candidates as $candidate) {
                array_push($arrays, ...$this->arraysIn($candidate));
            }
        }

        return $arrays;
    }

    /**
     * @return list<Expr\Array_>
     */
    private function arraysIn(?Expr $expression): array
    {
        if ($expression instanceof Expr\Array_) {
            return [$expression];
        }
        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name && $expression->name->toString() === 'array_merge') {
            return collect($expression->args)->flatMap(fn ($a) => $a instanceof Node\Arg ? $this->arraysIn($a->value) : [])->all();
        }
        if ($expression instanceof Expr\Ternary) {
            return [...$this->arraysIn($expression->if), ...$this->arraysIn($expression->else)];
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function rulesFrom(Expr\Array_ $array): array
    {
        $rules = [];
        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem || ! $item->key instanceof Node\Scalar\String_) {
                continue;
            }
            $rules[$item->key->value] = $this->tokens($item->value);
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function tokens(Expr $value): array
    {
        if ($value instanceof Node\Scalar\String_) {
            return array_values(array_filter(explode('|', $value->value)));
        }
        if (! $value instanceof Expr\Array_) {
            return [];
        }
        $tokens = [];
        foreach ($value->items as $item) {
            $rule = $item?->value;
            if ($rule instanceof Node\Scalar\String_) {
                array_push($tokens, ...explode('|', $rule->value));
            } elseif ($rule instanceof Expr\Variable && in_array($rule->name, ['req', 'required'], true)) {
                // Règles partagées : « $req = $creating ? 'required' : 'sometimes' ».
                $tokens[] = 'required-on-create';
            } elseif ($rule instanceof Expr\StaticCall && $rule->class instanceof Node\Name && $rule->class->getLast() === 'Rule' && $rule->name instanceof Node\Identifier && $rule->name->toString() === 'in') {
                $list = $rule->args[0] ?? null;
                if ($list instanceof Node\Arg && $list->value instanceof Expr\Array_) {
                    $values = collect($list->value->items)->map(fn ($i) => $i?->value instanceof Node\Scalar\String_ || $i?->value instanceof Node\Scalar\Int_ ? (string) $i->value->value : null);
                    if (! $values->contains(null)) {
                        $tokens[] = 'in:'.$values->implode(',');
                    }
                }
            }
        }

        return $tokens;
    }

    private function successStatus(ClassMethod $method): int
    {
        $source = (new Standard)->prettyPrint($method->stmts ?? []);
        if (str_contains($source, 'noContent()')) {
            return 204;
        }
        foreach ([201, 202] as $code) {
            if (preg_match('/(,\s*|setStatusCode\()'.$code.'\)/', $source)) {
                return $code;
            }
        }

        return 200;
    }

    /**
     * Codes HTTP littéraux émis par l'action et ses méthodes utilitaires (réponses et refus métier).
     *
     * @param  list<ClassMethod>  $nodes
     * @return list<int>
     */
    private function statuses(array $nodes): array
    {
        $literal = function (array $args, int $index): ?int {
            $value = ($args[$index] ?? null) instanceof Node\Arg ? $args[$index]->value : null;

            return $value instanceof Node\Scalar\Int_ && $value->value >= 200 && $value->value < 600 ? $value->value : null;
        };
        $codes = [];
        foreach ($nodes as $node) {
            foreach ($this->finder->find($node, fn (Node $n) => $n instanceof Expr\MethodCall || $n instanceof Expr\FuncCall || $n instanceof Expr\New_) as $call) {
                $name = match (true) {
                    $call instanceof Expr\New_ => $call->class instanceof Node\Name ? $call->class->getLast() : null,
                    $call instanceof Expr\FuncCall => $call->name instanceof Node\Name ? $call->name->toString() : null,
                    default => $call->name instanceof Node\Identifier ? $call->name->toString() : null,
                };
                $codes[] = match ($name) {
                    'BusinessException' => $literal($call->args, 2) ?? 409,
                    'json', 'response' => $literal($call->args, 1),
                    'setStatusCode', 'noContent', 'abort' => $literal($call->args, 0),
                    default => null,
                };
            }
        }
        $codes = array_values(array_unique(array_filter($codes)));
        sort($codes);

        return $codes;
    }

    private function shape(ClassMethod $method): string
    {
        $source = (new Standard)->prettyPrint($method->stmts ?? []);

        return match (true) {
            str_contains($source, 'noContent(') => 'empty',
            str_contains($source, '->paginated(') => 'page',
            (bool) preg_match('/(response\(\)|Storage::[^;]*|disk\([^)]*\))->(file|download|response|streamDownload|stream)\(|X-Accel-Redirect|BinaryFileResponse|StreamedResponse/', $source) => 'binary',
            str_contains($source, "'data' =>") => 'data',
            default => 'object',
        };
    }

    /**
     * @param  list<ClassMethod>  $nodes
     * @return list<string>
     */
    private function businessErrors(array $nodes): array
    {
        $codes = [];
        foreach ($nodes as $node) {
            foreach ($this->finder->findInstanceOf($node, Expr\New_::class) as $new) {
                if ($new->class instanceof Node\Name && $new->class->getLast() === 'BusinessException' && ($new->args[0] ?? null) instanceof Node\Arg && $new->args[0]->value instanceof Node\Scalar\String_) {
                    $codes[] = $new->args[0]->value->value;
                }
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }
}
