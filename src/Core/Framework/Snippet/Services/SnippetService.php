<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Snippet\Services;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Doctrine\FetchModeHelper;
use Shopware\Core\Framework\Snippet\Files\LanguageFileCollection;
use Shopware\Core\Framework\Snippet\Files\LanguageFileInterface;
use Shopware\Core\Framework\Struct\Uuid;
use Symfony\Component\Translation\MessageCatalogueInterface;

class SnippetService implements SnippetServiceInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var LanguageFileCollection
     */
    private $languageFileCollection;

    /**
     * @var SnippetFlattenerInterface
     */
    private $snippetFlattener;

    public function __construct(
        Connection $connection,
        SnippetFlattenerInterface $snippetFlattener,
        LanguageFileCollection $languageFileCollection
    ) {
        $this->connection = $connection;
        $this->languageFileCollection = $languageFileCollection;
        $this->snippetFlattener = $snippetFlattener;
    }

    /**
     * {@inheritdoc}
     */
    public function getList(Criteria $criteria): array
    {
        $metaData = $this->getSetMetaData();

        if (\count($metaData) <= 0) {
            return [
                'total' => 0,
                'data' => [],
            ];
        }

        $limit = $criteria->getLimit();
        $page = $criteria->getOffset() / $limit;

        $isoList = array_column($metaData, 'iso');

        $languageFiles = $this->getLanguageFilesByIso($isoList);

        $fileSnippets = [];
        $total = 0;
        foreach ($languageFiles as $iso => $isoLanguageFiles) {
            $fileSnippets[$iso]['snippets'] = $this->getSnippetsFromFiles($isoLanguageFiles);
            $total = max($total, \count($fileSnippets[$iso]['snippets']));
        }

        $fileSnippets = $this->fillBlankSnippets($isoList, $fileSnippets);

        $sets = [];
        $translationKeyList = [];
        foreach (array_keys($metaData) as $snippetSetId) {
            $iso = $metaData[$snippetSetId]['iso'];
            $set = $metaData[$snippetSetId];

            $currentfileSnippets = $fileSnippets[$iso]['snippets'];
            $currentfileSnippets = array_chunk($currentfileSnippets, $limit, true);

            $set['snippets'] = $currentfileSnippets[$page];

            $translationKeyList = array_keys($currentfileSnippets[$page]);
            $sets[] = $set;
        }

        $dbSnippetSets = $this->getDatabaseSnippets($translationKeyList);

        foreach ($sets as &$set) {
            $setSnippets = $dbSnippetSets[$set['id']] ?? [];
            $set['snippets'] = $this->mergeSnippets($set['snippets'], $setSnippets, $set['id']);
        }
        unset($set);

        return [
            'total' => $total,
            'data' => $this->mergeSnippetsComparison($sets),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getStorefrontSnippets(MessageCatalogueInterface $catalog, string $snippetSetId): array
    {
        $locale = $this->getLocaleBySnippetSetId($snippetSetId);

        $languageFiles = $this->languageFileCollection->getLanguageFilesByIso($locale);
        $fileSnippets = $catalog->all('messages');

        /** @var LanguageFileInterface $languageFile */
        foreach ($languageFiles as $key => $languageFile) {
            $flattenLanguageFileSnippets = $this->snippetFlattener->flatten(
                json_decode(file_get_contents($languageFile->getPath()), true) ?: []
            );

            $fileSnippets = array_replace_recursive(
                $fileSnippets,
                $flattenLanguageFileSnippets
            );
        }

        $snippets = array_replace_recursive(
            $fileSnippets,
            $this->fetchSnippetsFromDatabase($snippetSetId)
        );

        return $snippets;
    }

    private function getDefaultLocale(): string
    {
        $locale = $this->connection->createQueryBuilder()
            ->select(['code'])
            ->from('locale')
            ->where('id = :localeId')
            ->setParameter('localeId', Uuid::fromHexToBytes(Defaults::LOCALE_SYSTEM))
            ->execute()
            ->fetchColumn();

        return $locale ?: Defaults::LOCALE_EN_GB_ISO;
    }

    private function getLanguageFilesByIso(array $isoList): array
    {
        $result = [];
        foreach ($isoList as $iso) {
            $result[$iso] = $this->languageFileCollection->getLanguageFilesByIso($iso);
        }

        return $result;
    }

    private function getSnippetsFromFiles(array $languageFiles): array
    {
        $result = [];
        /** @var LanguageFileInterface $languageFile */
        foreach ($languageFiles as $key => $languageFile) {
            $flattenLanguageFileSnippets = $this->snippetFlattener->flatten(
                json_decode(file_get_contents($languageFile->getPath()), true) ?: []
            );

            $result = array_replace_recursive(
                $result,
                $flattenLanguageFileSnippets
            );
        }

        return $result;
    }

    private function mergeSnippetsComparison(array $sets): array
    {
        $result = [];
        foreach ($sets as $snippetSet) {
            foreach ($snippetSet['snippets'] as $translationKey => $snippet) {
                $result[$translationKey][] = $snippet;
            }
        }

        return $result;
    }

    private function mergeSnippets(array $fileSnippets, array $dbSnippets, string $snippetSetId): array
    {
        $snippets = [];
        foreach ($fileSnippets as $translationKey => $snippet) {
            $snippets[$translationKey] = [
                'id' => null,
                'value' => $snippet,
                'resetTo' => $snippet,
                'origin' => $snippet,
                'translationKey' => $translationKey,
                'setId' => $snippetSetId,
            ];
        }

        foreach ($dbSnippets as $dbSnippet) {
            if (!isset($snippets[$dbSnippet['translationKey']])) {
                continue;
            }

            $dbSnippet['origin'] = $fileSnippets[$dbSnippet['translationKey']];
            $dbSnippet['resetTo'] = $dbSnippet['value'];
            $snippets[$dbSnippet['translationKey']] = $dbSnippet;
        }

        return $snippets;
    }

    private function fetchSnippetsFromDatabase(string $snippetSetId): array
    {
        $snippets = $this->connection->createQueryBuilder()
            ->select(['snippet.translation_key', 'snippet.value'])
            ->from('snippet')
            ->where('snippet.snippet_set_id = :snippetSetId')
            ->setParameter('snippetSetId', Uuid::fromHexToBytes($snippetSetId))
            ->addGroupBy('snippet.translation_key')
            ->addGroupBy('snippet.id')
            ->execute()
            ->fetchAll();

        return FetchModeHelper::keyPair($snippets);
    }

    private function getDatabaseSnippets($translationKeyList): array
    {
        $result = $this->connection->createQueryBuilder()
            ->select(['snippet_set_id', 'id', 'translation_key AS translationKey', 'value', 'snippet_set_id AS setId'])
            ->from('snippet')
            ->where('translation_key IN (:translationKeyList)')
            ->setParameter('translationKeyList', $translationKeyList, Connection::PARAM_STR_ARRAY)
            ->execute()
            ->fetchAll();

        return $this->getDbSnippetSets(FetchModeHelper::group($result));
    }

    private function getLocaleBySnippetSetId(string $snippetSetId): string
    {
        $locale = $this->connection->createQueryBuilder()
            ->select(['iso'])
            ->from('snippet_set')
            ->where('id = :snippetSetId')
            ->setParameter('snippetSetId', Uuid::fromHexToBytes($snippetSetId))
            ->execute()
            ->fetchColumn();

        if ($locale === false) {
            $locale = $this->getDefaultLocale();
        }

        return $locale;
    }

    private function getSetMetaData(): array
    {
        $sets = $this->connection->createQueryBuilder()
            ->select(['LOWER(HEX(id)) as array_key', 'LOWER(HEX(id)) as id', 'name', 'base_file AS baseFile', 'iso'])
            ->from('snippet_set')
            ->execute()
            ->fetchAll(\PDO::FETCH_ASSOC);

        return FetchModeHelper::groupUnique($sets);
    }

    private function getDbSnippetSets(array $dbSnippets): array
    {
        $dbSnippetSets = [];
        foreach ($dbSnippets as $index => $dbSnippetSet) {
            $snippets = [];
            foreach ($dbSnippetSet as $snippet) {
                $snippet['id'] = Uuid::fromBytesToHex($snippet['id']);
                $snippet['setId'] = Uuid::fromBytesToHex($snippet['setId']);
                $snippets[] = $snippet;
            }
            $dbSnippetSets[Uuid::fromBytesToHex($index)] = $snippets;
        }

        return $dbSnippetSets;
    }

    private function fillBlankSnippets(array $isoList, array $fileSnippets): array
    {
        foreach ($isoList as $iso) {
            foreach ($isoList as $currentIso) {
                if ($iso === $currentIso) {
                    continue;
                }

                foreach ($fileSnippets[$iso]['snippets'] as $index => $snippet) {
                    if (!isset($fileSnippets[$currentIso]['snippets'][$index])) {
                        $fileSnippets[$currentIso]['snippets'][$index] = '';
                    }
                }

                ksort($fileSnippets[$currentIso]['snippets']);
            }
        }

        return $fileSnippets;
    }
}
