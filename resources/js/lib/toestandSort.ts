export const toestandSortRank = (
  key: string,
  opts?: { isRole?: boolean; isToestandsWeergave?: boolean }
): number => {
  if (opts?.isRole) {
    return 2;
  }

  if (opts?.isToestandsWeergave) {
    return 1;
  }

  const normalized = key.toLowerCase();

  if (normalized.includes('contactgegevens')) {
    return 1;
  }

  if (normalized.includes('beschrijving')) {
    return 0;
  }

  return 0;
};
