/*
 * Which continent each country is on, for "3 continents to go". Six inhabited
 * continents (Antarctica has no forum members yet). Countries spanning two are
 * put where most of their people are.
 */
const LIST: Record<string, string> = {
  af: 'ao bf bi bj bw cd cf cg ci cm cv dj dz eg eh er et ga gh gm gn gq gw ke km lr ls ly ma mg ml mr mu mw mz na ne ng re rw sc sd sh sl sn so ss st sz td tg tn tz ug yt za zm zw',
  as: 'ae af am az bd bh bn bt cn cy ge hk id il in iq ir jo jp kg kh kp kr kw kz la lb lk mm mn mo mv my np om ph pk ps qa sa sg sy th tj tl tm tr tw uz vn ye',
  eu: 'ad al at ax ba be bg by ch cz de dk ee es fi fo fr gb gg gi gr hr hu ie im is it je li lt lu lv mc md me mk mt nl no pl pt ro rs ru se si sj sk sm ua va xk',
  na: 'ag ai aw bb bl bm bq bs bz ca cr cu cw dm do gd gl gp gt hn ht jm kn ky lc mf mq ms mx ni pa pm pr sv sx tc tt us vc vg vi',
  sa: 'ar bo br cl co ec fk gf gy pe py sr uy ve',
  oc: 'as au ck fj fm gu ki mh mp nc nf nr nu nz pf pg pn pw sb tk to tv um vu wf ws',
};

const BY_COUNTRY: Record<string, string> = {};
for (const [continent, codes] of Object.entries(LIST)) for (const code of codes.split(' ')) BY_COUNTRY[code] = continent;

export const CONTINENTS = Object.keys(LIST).length;

export function continentOf(cc: string | null): string | null {
  return (cc && BY_COUNTRY[cc.toLowerCase()]) || null;
}
