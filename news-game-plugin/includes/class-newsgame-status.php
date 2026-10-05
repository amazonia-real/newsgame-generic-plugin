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
 * Regras (decididas em 05/10):
 *   Pronto     — eixos e perfis completos, aprovadas >= meta e TODAS as
 *                aprovadas com peso. É o que garante o veredito completo em
 *                qualquer partida.
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
     * Estado de uma instância, lendo o banco. Uma query de contagem.
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

        return self::avaliar( $inst, (int) ( $c->aprovadas ?? 0 ), (int) ( $c->pendentes ?? 0 ), (int) ( $c->sem_peso ?? 0 ) );
    }

    /**
     * A regra, pura: recebe a linha da instância e as três contagens.
     *
     * Separada de calcular() para a listagem poder passar as contagens que já
     * agrega no get_all(), sem uma query por linha.
     *
     * @return array{estado:string, rotulo:string, falta:string[]}
     */
    public static function avaliar( $inst, $aprovadas, $pendentes, $sem_peso ) {
        $meta   = (int) $inst->density;
        $matriz = self::matriz_completa( self::matriz_do_jogo( $inst->axis_matrix ?? '' ) );
        $vivas  = $aprovadas + $pendentes;

        $falta = array();
        if ( ! $matriz ) {
            $falta[] = 'eixos e perfis — "Analisar Acervo" e salvar a matriz';
        }
        if ( 0 === $vivas ) {
            $falta[] = 'nenhuma pergunta gerada';
        } elseif ( $aprovadas < $meta ) {
            $falta[] = sprintf( 'faltam %d aprovada(s) para a meta de %d', $meta - $aprovadas, $meta );
        }
        if ( $sem_peso > 0 ) {
            $falta[] = sprintf( '%d aprovada(s) sem peso', $sem_peso );
        }

        if ( $matriz && $aprovadas >= $meta && 0 === $sem_peso ) {
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
}
