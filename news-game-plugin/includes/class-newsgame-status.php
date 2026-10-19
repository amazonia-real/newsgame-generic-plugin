<?php
defined( 'ABSPATH' ) || exit;

/**
 * Status DERIVADO da instância: Rascunho → Em revisão → Pronto.
 *
 * CALCULADO NA LEITURA, NUNCA GRAVADO. Esta classe não consulta a coluna
 * `status` de wp_newsgame_instances, que é legado. Motivo: o estado depende
 * de ~10 escritas espalhadas por três arquivos — aprovar, descartar, devolver,
 * substituir, pesar, gerar,
 * limpar, salvar a meta, salvar a matriz — e uma coluna de cache precisaria ser
 * recalculada em todas. A que faltasse deixaria o selo mentindo em silêncio,
 * que é a família de defeito do C13.
 *
 * O status NÃO bloqueia nada: o shortcode renderiza em qualquer estado (D4,
 * fechado por decisão em 05/10). Ele só informa o editor do que falta.
 *
 * Regras (decididas em 05/10; o limiar mudou em 09/10 e de novo no item 58):
 *   Pronto     — eixos e perfis completos, TODAS as aprovadas com peso, gerais
 *                Prontas e, se a instância tem personagens, pelo menos um
 *                personagem Pronto (grupo Pronto = aprovadas >= meta dele; ver
 *                receita()). Personagem não Pronto entra no "o que falta" como
 *                aviso, sem bloquear: o jogo não o mostra.
 *   Em revisão — eixos e perfis completos e há perguntas vivas (aprovadas ou
 *                pendentes), mas falta algo para Pronto.
 *   Rascunho   — o resto: sem eixos/perfis, ou sem pergunta viva.
 */
class Newsgame_Status {

    const RASCUNHO = 'rascunho';
    const REVISAO  = 'revisao';
    const PRONTO   = 'pronto';

    const ROTULOS = array(
        self::RASCUNHO => 'Rascunho',
        self::REVISAO  => 'Em revisão',
        self::PRONTO   => 'Pronto',
    );

    /* Receita da partida (item 58). Os mesmos números estão em
       admin/js/newsgame-admin-form.js, na prévia do formulário. */
    const GRUPO_GERAL           = 'geral';
    const GERAIS_PCT_PADRAO     = 50;
    const GERAIS_PCT_MAX        = 50;
    const FATOR_DECIMOS_PADRAO  = 16;   // 1,6
    const FATOR_DECIMOS_MIN     = 16;
    const FATOR_DECIMOS_MAX     = 50;   // 5,0
    const CONJUNTO_MAX          = 120;

    /**
     * Receita da partida, pura: quantas perguntas cada grupo põe numa partida e
     * a meta de aprovadas de cada um.
     *
     * Sem personagens, as N perguntas são gerais e a regra dos 50% não entra.
     * Com personagens, G = máx(1, piso(N × pct / 100)) gerais e Y = N − G de UM
     * personagem: a partida é G gerais + Y do personagem escolhido.
     * Meta de um grupo = teto(o que ele põe na partida × fator). O fator vem em
     * DÉCIMOS (16 = 1,6) para a conta ser inteira: 1,6 × 5 em ponto flutuante
     * pode dar 8,000…01, e o teto viraria 9.
     *
     * @param int      $n             Perguntas por partida (round_size).
     * @param int      $gerais_pct    0 a 50.
     * @param int      $fator_decimos 16 a 50.
     * @param string[] $slugs         Slugs dos personagens da instância.
     * @return array{gerais_partida:int, por_personagem:int, metas:array<string,int>, total:int}
     */
    public static function receita( $n, $gerais_pct, $fator_decimos, array $slugs ) {
        $n      = (int) $n;
        $fator  = (int) $fator_decimos;
        $slugs  = array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
        $gerais = $slugs ? max( 1, intdiv( $n * (int) $gerais_pct, 100 ) ) : $n;
        $por    = $slugs ? $n - $gerais : 0;

        $metas = array( self::GRUPO_GERAL => intdiv( $gerais * $fator + 9, 10 ) );
        foreach ( $slugs as $slug ) {
            $metas[ $slug ] = intdiv( $por * $fator + 9, 10 );
        }

        return array(
            'gerais_partida' => $gerais,
            'por_personagem' => $por,
            'metas'          => $metas,
            'total'          => array_sum( $metas ),
        );
    }

    /**
     * Fator gravado (DECIMAL "1.6") em décimos, limitado à faixa. Coluna ausente
     * ou vazia cai no padrão.
     */
    public static function fator_decimos( $valor ) {
        if ( null === $valor || '' === $valor ) {
            return self::FATOR_DECIMOS_PADRAO;
        }
        $d = (int) round( (float) $valor * 10 );
        return min( self::FATOR_DECIMOS_MAX, max( self::FATOR_DECIMOS_MIN, $d ) );
    }

    /**
     * Painel da receita por grupo, lendo o banco: uma query agrupada por
     * `character_slug`. Descartadas não contam.
     *
     * Órfã = pergunta viva (aprovada ou pendente) de um slug que não é 'geral'
     * nem personagem atual da instância: personagem removido ou renomeado.
     *
     * @return array{grupos:array, total_meta:int, pendentes:int, orfas:int, sem_gerais:bool}|null
     */
    public static function grupos( $instance_id ) {
        $inst = Newsgame_Admin_Instances::get( $instance_id );
        if ( ! $inst ) {
            return null;
        }

        $mapa = self::contagem_por_grupo( (int) $instance_id );
        return self::avaliar_grupos( $inst, $mapa[ (int) $instance_id ] ?? array() );
    }

    /**
     * A regra do painel, pura: recebe a instância e a contagem por slug
     * (slug => [aprovadas, pendentes]).
     */
    public static function avaliar_grupos( $inst, array $contagem ) {
        $receita = self::receita_da_instancia( $inst );
        $chars   = json_decode( $inst->characters ?? '[]', true );
        $rotulos = array( self::GRUPO_GERAL => 'Gerais' );
        foreach ( is_array( $chars ) ? $chars : array() as $c ) {
            if ( isset( $c['slug'] ) && ! isset( $rotulos[ $c['slug'] ] ) ) {
                $rotulos[ $c['slug'] ] = (string) ( $c['label'] ?? $c['slug'] );
            }
        }

        $grupos = array();
        foreach ( $receita['metas'] as $slug => $meta ) {
            list( $aprovadas, $pendentes ) = $contagem[ $slug ] ?? array( 0, 0 );
            $grupos[] = array(
                'slug'      => $slug,
                'rotulo'    => $rotulos[ $slug ] ?? $slug,
                'na_partida'=> self::GRUPO_GERAL === $slug ? $receita['gerais_partida'] : $receita['por_personagem'],
                'meta'      => $meta,
                'aprovadas' => $aprovadas,
                'pendentes' => $pendentes,
                'faltam'    => max( 0, $meta - $aprovadas ),
                // O que o botão do grupo gera: a mesma conta do ajax_start() com
                // grupo — as pendentes já contam, porque ainda podem ser aprovadas.
                'a_gerar'   => max( 0, $meta - $aprovadas - $pendentes ),
                'pronto'    => $aprovadas >= $meta,
            );
        }

        $pendentes = 0;
        $orfas     = 0;
        foreach ( $contagem as $slug => $n ) {
            $pendentes += $n[1];
            if ( ! isset( $receita['metas'][ $slug ] ) ) {
                $orfas += $n[0] + $n[1];
            }
        }

        return array(
            'grupos'     => $grupos,
            'total_meta' => $receita['total'],
            'pendentes'  => $pendentes,
            'orfas'      => $orfas,
            // Com personagens e nenhuma geral aprovada: o jogo (rodada C) cai no
            // sorteio antigo. Sem personagens, "sem gerais" é "sem perguntas", e
            // o selo já diz isso.
            'sem_gerais' => count( $receita['metas'] ) > 1 && 0 === ( $contagem[ self::GRUPO_GERAL ][0] ?? 0 ),
        );
    }

    /** Receita de uma linha de wp_newsgame_instances. */
    public static function receita_da_instancia( $inst ) {
        $chars = json_decode( $inst->characters ?? '[]', true );
        $slugs = is_array( $chars ) ? array_column( $chars, 'slug' ) : array();
        return self::receita(
            (int) ( $inst->round_size ?? 10 ),
            (int) ( $inst->gerais_pct ?? self::GERAIS_PCT_PADRAO ),
            self::fator_decimos( $inst->pronto_fator ?? null ),
            $slugs
        );
    }

    /**
     * Perfis e eixos EXATAMENTE como o jogo os recebe.
     *
     * Fonte única: o shortcode monta o JSON do jogador com esta função, e
     * avaliar() decide "eixos e perfis completos" sobre o mesmo resultado. Se a
     * extração mudar, muda para os dois.
     *
     * @param  string|null $axis_matrix_json Coluna axis_matrix crua.
     * @return array{perfis:mixed, eixos:mixed}
     */
    public static function matriz_do_jogo( $axis_matrix_json ) {
        $m = $axis_matrix_json ? json_decode( (string) $axis_matrix_json, true ) : null;
        if ( ! is_array( $m ) ) {
            return array( 'perfis' => null, 'eixos' => null );
        }
        return array(
            'perfis' => $m['perfis_sugeridos'] ?? null,
            'eixos'  => $m['eixos_principais'] ?? null,
        );
    }

    /**
     * O portão do renderEnd() (newsgame-game.js), no lado do servidor.
     *
     * O jogo exige `data.perfis && data.eixos` verdadeiros, e depois precisa de
     * ao menos um perfil para a distância euclidiana escolher — lista VAZIA de
     * perfis passa no `&&` do JS (array vazio é truthy) mas cai no veredito
     * genérico porque `melhor` fica null. Por isso aqui é "não vazio" nos dois.
     *
     * A terceira condição do jogo, `pesoCount > 0`, é de RUNTIME (depende das
     * escolhas do jogador) e não cabe aqui. avaliar() a garante pelo lado forte:
     * todas as aprovadas com peso fazem pesoCount igual ao número de respostas.
     *
     * ⚠️ LIMITAÇÃO CONHECIDA, aceita em 05/10: "com peso" é `axis_weights IS
     * NOT NULL`, o mesmo critério de count_unweighted_approved(). Não confere se
     * há chave para CADA letra — o jogo lê `axis_weights[letra escolhida]`, e
     * uma pergunta de 3 opções com peso só em A e B contaria como pesada. Medido
     * em 05/10 nas 5 instâncias reais: nenhuma aprovada nessa situação, nem com
     * `axis_weights` vazio, JSON `null` ou `{}`.
     *
     * @param array $matriz Saída de matriz_do_jogo().
     */
    public static function matriz_completa( array $matriz ) {
        return is_array( $matriz['perfis'] ) && count( $matriz['perfis'] ) > 0
            && is_array( $matriz['eixos'] ) && count( $matriz['eixos'] ) > 0;
    }

    /**
     * Estado de uma instância, lendo o banco. Duas queries de contagem: o total e
     * a contagem por grupo.
     *
     * @return array{estado:string, rotulo:string, falta:string[]}|null Null se a instância não existe.
     */
    public static function calcular( $instance_id ) {
        $inst = Newsgame_Admin_Instances::get( $instance_id );
        if ( ! $inst ) {
            return null;
        }

        global $wpdb;
        $c = $wpdb->get_row( $wpdb->prepare(
            "SELECT SUM(review_status = 'approved')                        AS aprovadas,
                    SUM(review_status = 'pending')                         AS pendentes,
                    SUM(review_status = 'approved' AND axis_weights IS NULL) AS sem_peso
               FROM {$wpdb->prefix}newsgame_dilemas
              WHERE instance_id = %d",
            (int) $instance_id
        ) );
        $por_grupo = self::contagem_por_grupo( (int) $instance_id );

        return self::avaliar( $inst, (int) ( $c->aprovadas ?? 0 ), (int) ( $c->pendentes ?? 0 ), (int) ( $c->sem_peso ?? 0 ), $por_grupo[ (int) $instance_id ] ?? array() );
    }

    /**
     * Aprovadas e pendentes por grupo (`character_slug`), de uma instância ou de
     * TODAS (listagem): instance_id => slug => [aprovadas, pendentes]. Uma query.
     */
    public static function contagem_por_grupo( $instance_id = 0 ) {
        global $wpdb;
        $onde   = $instance_id ? $wpdb->prepare( 'WHERE instance_id = %d', (int) $instance_id ) : '';
        $linhas = $wpdb->get_results(
            "SELECT instance_id, character_slug AS slug,
                    SUM(review_status = 'approved') AS aprovadas,
                    SUM(review_status = 'pending')  AS pendentes
               FROM {$wpdb->prefix}newsgame_dilemas
               $onde
              GROUP BY instance_id, character_slug"
        );
        $mapa = array();
        foreach ( (array) $linhas as $l ) {
            $mapa[ (int) $l->instance_id ][ (string) $l->slug ] = array( (int) $l->aprovadas, (int) $l->pendentes );
        }
        return $mapa;
    }

    /**
     * Quais grupos estão Prontos (item 58), pura. Personagem Pronto é o que o
     * jogo pode sortear; o selo pede as gerais Prontas e, havendo personagens,
     * pelo menos um deles.
     *
     * @return array{gerais_pronto:bool, faltam_gerais:int, tem_personagens:bool, prontos:string[], nao_prontos:string[]}
     */
    public static function prontidao( $inst, array $contagem ) {
        $painel = self::avaliar_grupos( $inst, $contagem );
        $out    = array( 'gerais_pronto' => false, 'faltam_gerais' => 0, 'tem_personagens' => false, 'prontos' => array(), 'nao_prontos' => array() );
        foreach ( $painel['grupos'] as $g ) {
            if ( self::GRUPO_GERAL === $g['slug'] ) {
                $out['gerais_pronto'] = $g['pronto'];
                $out['faltam_gerais'] = $g['faltam'];
                continue;
            }
            $out['tem_personagens'] = true;
            if ( $g['pronto'] ) {
                $out['prontos'][] = $g['slug'];
            } else {
                $out['nao_prontos'][] = sprintf( '%s (faltam %d)', $g['rotulo'], $g['faltam'] );
            }
        }
        return $out;
    }

    /**
     * A regra, pura: recebe a linha da instância, as três contagens e a contagem
     * por grupo (slug => [aprovadas, pendentes]).
     *
     * Separada de calcular() para a listagem poder passar as contagens que já
     * agrega, sem uma query por linha.
     *
     * Pronto (item 58, rodada C): eixos e perfis completos, todas as aprovadas
     * com peso, gerais Prontas e, se a instância tem personagens, pelo menos um
     * personagem Pronto. Continua NÃO bloqueando nada (05/10).
     *
     * @return array{estado:string, rotulo:string, falta:string[]}
     */
    public static function avaliar( $inst, $aprovadas, $pendentes, $sem_peso, array $contagem ) {
        $matriz  = self::matriz_completa( self::matriz_do_jogo( $inst->axis_matrix ?? '' ) );
        $vivas   = $aprovadas + $pendentes;
        $pr      = self::prontidao( $inst, $contagem );
        $grupos  = $pr['gerais_pronto'] && ( ! $pr['tem_personagens'] || $pr['prontos'] );

        $falta = array();
        if ( ! $matriz ) {
            $falta[] = 'eixos e perfis — "Analisar Acervo" e salvar a matriz';
        }
        if ( 0 === $vivas ) {
            $falta[] = 'nenhuma pergunta gerada';
        } else {
            $falta = array_merge( $falta, self::falta_dos_grupos( $pr ) );
        }
        if ( $sem_peso > 0 ) {
            $falta[] = sprintf( '%d aprovada(s) sem peso', $sem_peso );
        }

        if ( $matriz && $grupos && 0 === $sem_peso ) {
            $estado = self::PRONTO;
        } elseif ( $matriz && $vivas > 0 ) {
            $estado = self::REVISAO;
        } else {
            $estado = self::RASCUNHO;
        }

        return array(
            'estado' => $estado,
            'rotulo' => self::ROTULOS[ $estado ],
            'falta'  => $falta,
        );
    }

    /**
     * Itens do "o que falta" que vêm dos grupos. Personagem não Pronto com outro
     * já Pronto não bloqueia o selo, mas entra como aviso: ele fica fora do jogo.
     */
    private static function falta_dos_grupos( array $pr ) {
        $falta = array();
        if ( ! $pr['gerais_pronto'] ) {
            $falta[] = sprintf( 'gerais: faltam %d aprovada(s)', $pr['faltam_gerais'] );
        }
        if ( $pr['tem_personagens'] && ! $pr['prontos'] ) {
            $falta[] = 'nenhum personagem pronto: ' . implode( ', ', $pr['nao_prontos'] );
        } elseif ( $pr['nao_prontos'] ) {
            $falta[] = 'fora do jogo até ficarem prontos: ' . implode( ', ', $pr['nao_prontos'] );
        }
        return $falta;
    }
}
