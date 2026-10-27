const dashOnly = (str) => {
  return /^[\-]+$/.test(str);
};

const spaceonly = (str) => {
  return /^[\s]+$/.test(str);
};

export default {
  dashOnly,
  spaceonly,
};
