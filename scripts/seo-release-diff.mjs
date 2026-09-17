export function managedReleaseDiff(previous, current) {
  const before = new Set(previous?.managedFiles || previous?.files || []);
  const after = new Set(current?.managedFiles || current?.files || []);
  return {
    add: [...after].filter(file => !before.has(file)).sort(),
    replace: [...after].filter(file => before.has(file)).sort(),
    remove: [...before].filter(file => !after.has(file)).sort(),
  };
}
