const ID = {
  PASSPORT: {
    expiry: true,
    issued: true,
  },
  DRVLICENSE: {
    expiry: true,
    issued: false,
  },
  PRC: {
    expiry: true,
    issued: true,
  },
  BIR: {
    expiry: false,
    issued: true,
  },
  SSS: {
    expiry: false,
    issued: true,
  },
  GSIS: {
    expiry: true,
    issued: false,
  },
  OWWA: {
    expiry: false,
    issued: true,
  },
  DPOSTID: {
    expiry: true,
    issued: false,
  },
  IBP: {
    expiry: false,
    issued: true,
  },
  OFW: {
    expiry: false,
    issued: true,
  },
  GOVT: {
    either_or: true,
  },
  SRCITIZEN: {
    expiry: false,
    issued: true,
  },
  COMPANY: {
    expiry: false,
    issued: true,
  },
  PHILID: {
    expiry: false,
    issued: true,
  },
  PAGIBIG: {
    expiry: true,
    issued: false,
  },
  PHILHEALTH: {
    expiry: false,
    issued: true,
  },
};

const checkID = (idName) => {
  return ID[idName];
};

module.exports = {
  checkID,
};
