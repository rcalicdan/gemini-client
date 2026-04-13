<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Rcalicdan\GeminiClient\Interfaces\GeminiClientInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiSearchInterface;

use function Hibla\async;
use function Hibla\await;

class GeminiSearch implements GeminiSearchInterface
{
    /**
     * @var array<string>
     */
    private array $documents = [];

    private ?string $model = null;

    private ?int $outputDimensionality = null;

    public function __construct(
        private GeminiClientInterface $client,
        private GeminiRequestBuilder $builder,
        private string $query
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string> $documents
     */
    public function documents(array $documents): static
    {
        $clone = clone $this;
        $clone->documents = $documents;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function model(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function outputDimensionality(int $dimensions): static
    {
        $clone = clone $this;
        $clone->outputDimensionality = $dimensions;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function send(): PromiseInterface
    {
        if (\count($this->documents) === 0) {
            throw new \RuntimeException('Cannot execute search without providing documents to search against.');
        }

        return async(function () {
            $queryBuilder = $this->client->embed($this->query)
                ->taskType('RETRIEVAL_QUERY')
            ;

            if ($this->model !== null) {
                $queryBuilder = $queryBuilder->model($this->model);
            }

            if ($this->outputDimensionality !== null) {
                $queryBuilder = $queryBuilder->outputDimensionality($this->outputDimensionality);
            }

            $queryResponse = await($queryBuilder->send());

            $rawQueryEmbedding = $queryResponse->values();
            /** @var array<float> $queryEmbedding */
            $queryEmbedding = \is_array($rawQueryEmbedding[0] ?? null)
                ? ($rawQueryEmbedding[0] ?? [])
                : $rawQueryEmbedding;

            $docPromises = [];
            foreach ($this->documents as $doc) {
                $docBuilder = $this->client->embed($doc)
                    ->taskType('RETRIEVAL_DOCUMENT')
                ;

                if ($this->model !== null) {
                    $docBuilder = $docBuilder->model($this->model);
                }

                if ($this->outputDimensionality !== null) {
                    $docBuilder = $docBuilder->outputDimensionality($this->outputDimensionality);
                }

                $docPromises[] = $docBuilder->send();
            }

            /** @var array<GeminiEmbeddingResponse> $docResponses */
            $docResponses = await(Promise::all($docPromises));

            /** @var array<array{text: string, similarity: float, index: int}> $results */
            $results = [];

            foreach ($docResponses as $index => $docResponse) {
                $rawDocEmbedding = $docResponse->values();
                /** @var array<float> $docEmbedding */
                $docEmbedding = \is_array($rawDocEmbedding[0] ?? null)
                    ? ($rawDocEmbedding[0] ?? [])
                    : $rawDocEmbedding;

                $similarity = $this->builder->cosineSimilarity($queryEmbedding, $docEmbedding);

                $results[] = [
                    'text' => $this->documents[$index],
                    'similarity' => $similarity,
                    'index' => (int) $index,
                ];
            }

            usort($results, fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);

            return $results;
        });
    }
}
