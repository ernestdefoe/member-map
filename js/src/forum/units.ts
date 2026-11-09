/** Countries that measure road distances in miles. */
const MILES = ['US', 'GB', 'LR', 'MM'];

export function usesMiles(): boolean {
  const languages = navigator.languages?.length ? navigator.languages : [navigator.language];
  const region = (languages.find((language) => language?.includes('-')) || '').split('-').pop()?.toUpperCase();
  return !!region && MILES.includes(region);
}

/** Great-circle distance in km. */
export function distanceKm(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const rad = Math.PI / 180;
  const a = Math.sin(((lat2 - lat1) * rad) / 2) ** 2 + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(((lng2 - lng1) * rad) / 2) ** 2;
  return 12742 * Math.asin(Math.min(1, Math.sqrt(a)));
}

/**
 * A rough distance, never more exact than the places themselves (a city's
 * centre): under 15 km is "in your area", then steps of 5, 10 and 50.
 */
export function roughDistance(km: number, miles: boolean): number | null {
  if (km < 15) return null;
  const value = miles ? km * 0.621371 : km;
  const step = value < 100 ? 5 : value < 1000 ? 10 : 50;
  return Math.max(step, Math.round(value / step) * step);
}
