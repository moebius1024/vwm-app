export const toestandSortRank = (
  _key: string,
  opts?: { isRole?: boolean; isToestandsWeergave?: boolean }
): number => {
  if (opts?.isRole) {
    return 2;
  }

  if (opts?.isToestandsWeergave) {
    return 1;
  }

  return 0;
};
